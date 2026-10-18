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
     * @param  array<int, array<mixed>>  $rawRows   data rows (no header)
     * @return array<int, int|string>    persisted primary keys
     */
    public function importRows(string $modelClass, array $headers, array $rawRows): array
    {
        /** @var SheetImportable $model */
        $model = new $modelClass;
        $keyColumn = $model->getSheetKeyColumn();

        if (! in_array($keyColumn, $headers, true)) {
            throw new ImportException(
                "Sheet is missing the key column [{$keyColumn}] required to import {$modelClass}"
            );
        }

        $preparedRows = $this->prepareRows($headers, $rawRows, $keyColumn, null);
        if (empty($preparedRows)) {
            return [];
        }

        return $this->persist($modelClass, $preparedRows);
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

        $keyColumn = $model->getSheetKeyColumn();
        if (! in_array($keyColumn, $headers, true)) {
            throw new ImportException(
                "Sheet is missing the key column [{$keyColumn}] required to import {$modelClass}"
            );
        }

        $allowed = $allowedIds === null
            ? null
            : array_map('strval', $allowedIds);

        $totalProcessed = 0;
        $lastProcessedId = null;

        foreach (array_chunk($values, $batchSize) as $chunk) {
            $processedIds = $this->persist($modelClass, $this->prepareRows($headers, $chunk, $keyColumn, $allowed));

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
     * Turn raw sheet rows into [key, assoc] pairs, applying the partial filter.
     *
     * @return array<int, array{key: mixed, assoc: array<string, mixed>}>
     */
    private function prepareRows(array $headers, array $chunk, string $keyColumn, ?array $allowed): array
    {
        $prepared = [];

        foreach ($chunk as $row) {
            $assoc = $this->transformer->parseRow($this->rowToAssoc($headers, $row));

            $keyValue = $assoc[$keyColumn] ?? null;
            if (is_float($keyValue) && floor($keyValue) === $keyValue) {
                $keyValue = (int) $keyValue; // typed (unformatted) reads return numbers
            }
            if ($keyValue === null) {
                // A row without a primary key cannot be matched; skip it.
                continue;
            }

            if ($allowed !== null && ! in_array((string) $keyValue, $allowed, true)) {
                continue;
            }

            $prepared[] = ['key' => $keyValue, 'assoc' => $assoc];
        }

        return $prepared;
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
