<?php
/*
 *          M""""""""`M            dP
 *          Mmmmmm   .M            88
 *          MMMMP  .MMM  dP    dP  88  .dP   .d8888b.
 *          MMP  .MMMMM  88    88  88888"    88'  `88
 *          M' .MMMMMMM  88.  .88  88  `8b.  88.  .88
 *          M         M  `88888P'  dP   `YP  `88888P'
 *          MMMMMMMMMMM    -*-  Created by Zuko  -*-
 *
 *          * * * * * * * * * * * * * * * * * * * * *
 *          * -    - -   F.R.E.E.M.I.N.D   - -    - *
 *          * -  Copyright © 2024 (Z) Programing  - *
 *          *    -  -  All Rights Reserved  -  -    *
 *          * * * * * * * * * * * * * * * * * * * * *
 */

namespace Zuko\SyncroSheet\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Zuko\SyncroSheet\Contracts\SheetImportable;
use Zuko\SyncroSheet\Exceptions\ImportException;
use Zuko\SyncroSheet\Models\SyncState;

/**
 * Import engine: pulls rows FROM a Google Sheet back into Eloquent records
 * (Sheet -> DB). Counterpart of {@see Exporter}.
 *
 * Rows are matched to records through the primary-key column declared by
 * {@see SheetImportable::getSheetKeyColumn()} and persisted with upsert
 * semantics (update when matched, create when new; never deletes).
 */
class Importer
{
    public function __construct(
        private readonly StateManager $stateManager,
        private readonly DataTransformer $transformer,
        private readonly GoogleClient $googleClient,
        private readonly SyncLogger $logger
    ) {
    }

    /**
     * Import every row of the sheet into the model table.
     */
    public function import(string $modelClass, SyncState $syncState): array
    {
        return $this->readAndPersist($modelClass, $syncState, null);
    }

    /**
     * Import only the rows whose key matches one of $recordIds.
     */
    public function importPartial(string $modelClass, array $recordIds, SyncState $syncState): array
    {
        return $this->readAndPersist($modelClass, $syncState, $recordIds);
    }

    /**
     * Upsert a set of already-read sheet rows (no sheet read here). Used by the
     * queue driver's ImportBatchJob, which carries the rows in its payload.
     *
     * @param  array<int, string>        $headers   the sheet header row
     * @param  array<int, array<mixed>>  $rawRows   data rows (no header), keyed by data-row offset
     * @return array<int, int|string>    persisted primary keys
     */
    public function importRows(string $modelClass, array $headers, array $rawRows): array
    {
        /** @var Model&SheetImportable $model */
        $model = new $modelClass;
        $this->assertKeyHeader($model, $headers);

        return $this->persistChunk($model, $this->prepareRows($model, $headers, $rawRows, null));
    }

    /**
     * Read the whole sheet, then upsert the (optionally filtered) rows in
     * memory-efficient chunks.
     *
     * @param  array<int, mixed>|null  $allowedIds  null = import all rows
     */
    private function readAndPersist(string $modelClass, SyncState $syncState, ?array $allowedIds): array
    {
        /** @var SheetImportable $model */
        $model = new $modelClass;

        $batchSize = method_exists($model, 'getBatchSize')
            ? $model->getBatchSize()
            : config('syncro-sheet.defaults.batch_size');

        $values = $this->googleClient->readSheet(
            $model->getSheetIdentifier(),
            $model->getSheetName()
        );

        // First row holds the headers; nothing to import without them.
        $headers = array_shift($values);
        if (empty($headers) || empty($values)) {
            $this->logger->info("No rows to import for {$modelClass}");

            return ['total_processed' => 0, 'last_processed_id' => null];
        }

        $this->assertKeyHeader($model, $headers);

        $allowed = $allowedIds === null
            ? null
            : array_map('strval', $allowedIds);

        $totalProcessed = 0;
        $lastProcessedId = null;

        // Keys preserved: a row's offset gives its sheet row number.
        foreach (array_chunk($values, $batchSize, true) as $chunk) {
            $processedIds = $this->persistChunk($model, $this->prepareRows($model, $headers, $chunk, $allowed));

            if (empty($processedIds)) {
                continue;
            }

            $this->stateManager->recordBatchSync($syncState, $processedIds);

            $processedCount = count($processedIds);
            $totalProcessed += $processedCount;
            $lastProcessedId = max($processedIds);

            $this->logger->info("Imported chunk of {$processedCount} records for {$modelClass}");
        }

        return [
            'total_processed' => $totalProcessed,
            'last_processed_id' => $lastProcessedId,
        ];
    }

    /**
     * Upsert one sheet row into the record whose primary key is $key (shared
     * by import and two-way reconcile).
     *
     * fromSheetRow() runs on the target record itself (the existing one, or a
     * new instance), so a model can merge into attributes it already holds.
     * A new record keeps the sheet's primary key even when the key is guarded.
     * Soft-deleted records are left alone (returns null).
     *
     * @param  array<string, mixed>  $assoc  parsed sheet row (header => value)
     */
    public function upsertRow(string $modelClass, int|string $key, array $assoc): ?Model
    {
        /** @var Model&SheetImportable $record */
        $record = $this->findWithTrashed($modelClass, $key) ?? new $modelClass;

        if ($record->exists && method_exists($record, 'trashed') && $record->trashed()) {
            return null;
        }

        $attributes = $record->fromSheetRow($assoc);
        unset($attributes[$record->getSheetKeyColumn()], $attributes[$record->getKeyName()]);

        $record->fill($attributes);
        if (! $record->exists) {
            $record->setAttribute($record->getKeyName(), $key);
        }
        $record->save();

        return $record;
    }

    /**
     * After rows were inserted with explicit keys, move a PostgreSQL key
     * sequence past MAX(key) so later inserts do not collide. It never moves
     * the sequence backwards. Other drivers advance auto-increment by themselves.
     */
    public function syncKeySequence(string $modelClass): void
    {
        /** @var Model $model */
        $model = new $modelClass;
        $connection = $model->getConnection();

        if (! $model->getIncrementing() || $connection->getDriverName() !== 'pgsql') {
            return;
        }

        $grammar = $connection->getQueryGrammar();
        $table = $grammar->wrapTable($model->getTable());
        $key = $grammar->wrap($model->getKeyName());

        $connection->statement(
            "select setval(seq::regclass, greatest(coalesce((select max({$key}) from {$table}), 0), "
            .'coalesce(pg_sequence_last_value(seq::regclass), 0), 1)) '
            .'from pg_get_serial_sequence(?, ?) as seq where seq is not null',
            [$table, $model->getKeyName()]
        );
    }

    private function findWithTrashed(string $modelClass, int|string $key): ?Model
    {
        $query = $modelClass::query();
        if (in_array(SoftDeletes::class, class_uses_recursive($modelClass), true)) {
            $query->withTrashed();
        }

        return $query->find($key);
    }

    /**
     * The key is read by position, so its header may be blank; a non-blank
     * header at that position must be the key column (otherwise the index
     * points at another column and every row would look keyless).
     *
     * @param  array<int, mixed>  $headers  the sheet header row
     */
    public function assertKeyHeader(SheetImportable $model, array $headers): void
    {
        $keyIndex = $model->getSheetKeyColumnIndex();
        $header = $headers[$keyIndex] ?? '';

        if ($header !== '' && $header !== null && $header !== $model->getSheetKeyColumn()) {
            throw new ImportException(
                'Sheet is missing the key column ['.$model->getSheetKeyColumn().'] required to import '
                .get_class($model).": column {$keyIndex} holds [{$header}]"
            );
        }
    }

    /**
     * Create records from sheet rows that have no key yet, then write each new
     * key back into the row's key cell, so the row is matched from then on.
     *
     * Rows are re-read first: one that changed or moved since it was read
     * (edited, or shifted by a row inserted above) is left for the next run.
     * Creates and the write-back share one DB transaction, so a failed sheet
     * write leaves no record behind to be duplicated on the next run.
     *
     * @param  array<int, array{raw: array<int, mixed>, assoc: array<string, mixed>}>  $rows  rowIndex => row
     * @param  (callable(array<int, Model>): void)|null  $writeBack  rowIndex => created record; default writes the key cell
     * @return array<int, Model>  rowIndex => created record
     */
    public function createFromSheetRows(SheetImportable $model, array $rows, ?callable $writeBack = null): array
    {
        // The gate and the attributes once per row (fromSheetRow() may query).
        $attributes = [];
        foreach ($rows as $rowIndex => $row) {
            if (method_exists($model, 'canImportSheetRow') && ! $model->canImportSheetRow($row['assoc'])) {
                continue;
            }
            if (($values = $this->newRecordAttributes($model, $row['assoc'])) !== []) {
                $attributes[$rowIndex] = $values;
            }
        }

        $rows = $this->stillUnchanged($model, array_intersect_key($rows, $attributes));
        if (empty($rows)) {
            return [];
        }

        return $model->getConnection()->transaction(function () use ($model, $rows, $attributes, $writeBack) {
            $created = [];
            foreach (array_keys($rows) as $rowIndex) {
                $record = $model->newInstance();
                $record->fill($attributes[$rowIndex])->save();
                $created[$rowIndex] = $record;
            }

            ($writeBack ?? fn (array $records) => $this->writeKeys($model, $records))($created);

            return $created;
        });
    }

    /**
     * May this keyless sheet row become a record? The model's
     * canImportSheetRow() (InteractsWithSheet, which asks the model's
     * shouldImportSheetRow() hook) decides, and the row must map to
     * attributes.
     *
     * @param  array<string, mixed>  $assoc  parsed sheet row
     */
    public function canCreateFromSheetRow(SheetImportable $model, array $assoc): bool
    {
        if (method_exists($model, 'canImportSheetRow') && ! $model->canImportSheetRow($assoc)) {
            return false;
        }

        return $this->newRecordAttributes($model, $assoc) !== [];
    }

    /**
     * fromSheetRow() of a new instance, without the key ([] = nothing to create).
     */
    public function newRecordAttributes(SheetImportable $model, array $assoc): array
    {
        $attributes = $model->newInstance()->fromSheetRow($assoc);
        unset($attributes[$model->getSheetKeyColumn()], $attributes[$model->getKeyName()]);

        return $attributes;
    }

    /**
     * @param  array<int, array{raw: array<int, mixed>, assoc: array<string, mixed>}>  $rows
     * @return array<int, array{raw: array<int, mixed>, assoc: array<string, mixed>}>
     */
    private function stillUnchanged(SheetImportable $model, array $rows): array
    {
        if (empty($rows)) {
            return [];
        }

        $current = $this->googleClient->readRows($model->getSheetIdentifier(), $model->getSheetName(), array_keys($rows));

        return array_filter(
            $rows,
            fn (array $row, int $rowIndex) => $this->trimRow($current[$rowIndex] ?? []) === $this->trimRow($row['raw']),
            ARRAY_FILTER_USE_BOTH
        );
    }

    /**
     * @param  array<int, Model>  $records  rowIndex => created record
     */
    private function writeKeys(SheetImportable $model, array $records): void
    {
        $keyIndex = $model->getSheetKeyColumnIndex();

        $this->googleClient->updateRows(
            $model->getSheetIdentifier(),
            $model->getSheetName(),
            array_map(static fn (Model $record) => [$keyIndex => $record->getKey()], $records)
        );
    }

    /**
     * Split raw sheet rows into keyed rows (to upsert) and keyless rows (to
     * create), applying the partial filter. $chunk keys are data-row offsets
     * (0 = the row under the header).
     *
     * @return array{keyed: array<int, array{key: mixed, assoc: array<string, mixed>}>, keyless: array<int, array{raw: array<int, mixed>, assoc: array<string, mixed>}>}
     */
    private function prepareRows(SheetImportable $model, array $headers, array $chunk, ?array $allowed): array
    {
        $prepared = ['keyed' => [], 'keyless' => []];
        $keyIndex = $model->getSheetKeyColumnIndex();
        $codec = $this->transformer->forModel($model);

        foreach ($chunk as $offset => $row) {
            if ($this->trimRow($row) === []) {
                continue; // blank line
            }

            $keyValue = $this->normalizeKey($row[$keyIndex] ?? null);
            $assoc = $codec->parseRow($this->rowToAssoc($headers, $row));
            $assoc[$model->getSheetKeyColumn()] = $keyValue;

            if ($keyValue === null) {
                if ($allowed === null) {
                    $prepared['keyless'][$offset + 2] = ['raw' => $row, 'assoc' => $assoc];
                }

                continue;
            }

            if ($this->isAcceptedKey($model, $keyValue, $allowed)) {
                $prepared['keyed'][] = ['key' => $keyValue, 'assoc' => $assoc];
            }
        }

        return $prepared;
    }

    private function isAcceptedKey(SheetImportable $model, mixed $keyValue, ?array $allowed): bool
    {
        if ($model->getKeyType() === 'int' && ! is_int($keyValue) && ! ctype_digit((string) $keyValue)) {
            $this->logger->warning('Skipping sheet row with a non-integer key ['.$keyValue.'] for '.get_class($model));

            return false;
        }

        return $allowed === null || in_array((string) $keyValue, $allowed, true);
    }

    /**
     * Key cell -> key value: typed reads return numbers, text is trimmed,
     * blank -> null.
     */
    public function normalizeKey(mixed $value): int|string|null
    {
        if (is_float($value) && floor($value) === $value) {
            return (int) $value;
        }

        $value = is_string($value) ? trim($value) : $value;

        return ($value === null || $value === '') ? null : $value;
    }

    /**
     * Row values without trailing blanks (Google trims them on read).
     *
     * @return array<int, mixed>
     */
    public function trimRow(array $row): array
    {
        $row = array_values($row);
        while ($row !== [] && (end($row) === '' || end($row) === null)) {
            array_pop($row);
        }

        return $row;
    }

    /**
     * Upsert the keyed rows and create the keyless ones of one chunk.
     *
     * @return array<int, mixed>  persisted primary keys
     */
    private function persistChunk(SheetImportable $model, array $rows): array
    {
        $ids = $this->persist(get_class($model), $rows['keyed']);

        foreach ($this->createFromSheetRows($model, $rows['keyless']) as $record) {
            $ids[] = $record->getKey();
        }

        return $ids;
    }

    /**
     * @param  array<int, array{key: mixed, assoc: array<string, mixed>}>  $preparedRows
     * @return array<int, mixed>  persisted primary keys
     */
    private function persist(string $modelClass, array $preparedRows): array
    {
        if (empty($preparedRows)) {
            return [];
        }

        $ids = (bool) config('syncro-sheet.defaults.import.use_batch_upsert', false)
            ? $this->batchUpsert($modelClass, $preparedRows)
            : $this->rowByRowUpsert($modelClass, $preparedRows);

        $this->syncKeySequence($modelClass);

        return $ids;
    }

    /**
     * Per-row upsert: fires model events and respects casts.
     *
     * @return array<int, mixed>  persisted primary keys
     */
    private function rowByRowUpsert(string $modelClass, array $preparedRows): array
    {
        $ids = [];

        foreach ($preparedRows as $prepared) {
            $record = $this->upsertRow($modelClass, $prepared['key'], $prepared['assoc']);
            if ($record !== null) {
                $ids[] = $record->getKey();
            }
        }

        return $ids;
    }

    /**
     * Batch upsert via Model::upsert(): one query per chunk, but skips model
     * events and mutators, and maps rows on a fresh instance. Opt-in through
     * config for large, trusted imports.
     *
     * @return array<int, mixed>  persisted primary keys
     */
    private function batchUpsert(string $modelClass, array $preparedRows): array
    {
        /** @var Model&SheetImportable $model */
        $model = new $modelClass;
        $modelKey = $model->getKeyName();

        $values = array_map(static function ($prepared) use ($model, $modelKey) {
            $attributes = $model->fromSheetRow($prepared['assoc']);
            unset($attributes[$model->getSheetKeyColumn()]);
            $attributes[$modelKey] = $prepared['key'];

            return $attributes;
        }, $preparedRows);

        $modelClass::upsert($values, [$modelKey]);

        return array_map(static fn ($prepared) => $prepared['key'], $preparedRows);
    }

    /**
     * Combine a header list with a (possibly ragged) data row into an
     * associative array, padding/truncating to the header count.
     *
     * @return array<string, mixed>
     */
    private function rowToAssoc(array $headers, array $row): array
    {
        $size = count($headers);
        $row = array_pad(array_slice($row, 0, $size), $size, null);

        return array_combine($headers, $row);
    }
}
