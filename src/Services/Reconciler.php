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
use Zuko\SyncroSheet\Contracts\SheetSyncable;
use Zuko\SyncroSheet\Exceptions\ImportException;
use Zuko\SyncroSheet\Models\SyncState;

/**
 * Two-way reconcile engine (Sheet <-> DB) for bidirectional models.
 *
 * Uses a per-record content-hash baseline (via {@see StateManager}) to decide
 * which side actually changed since the last sync; genuine conflicts (both
 * sides changed) are resolved by a fixed source-of-truth. Upsert semantics on
 * the DB side (shared with {@see Importer}); cell-level writes on the sheet
 * side. Nothing is deleted, and soft-deleted records are left alone.
 */
class Reconciler
{
    public function __construct(
        private readonly DataTransformer $transformer,
        private readonly GoogleClient $googleClient,
        private readonly StateManager $stateManager,
        private readonly Importer $importer,
        private readonly SyncLogger $logger
    ) {
    }

    /**
     * Full two-way reconcile in one pass (driver=sync). Equivalent to
     * decide() followed by applyChunk() over the whole plan.
     */
    public function reconcile(string $modelClass, SyncState $syncState): array
    {
        $plan = $this->decide($modelClass);

        $pks = $this->applyChunk($modelClass, $plan['decisions']);

        // Seed a baseline for records that already agreed (no action needed).
        $this->stateManager->storeSnapshots($modelClass, $plan['seeds']);

        if (! empty($pks)) {
            $this->stateManager->recordBatchSync($syncState, $pks);
        }

        $this->logger->info("Reconciled {$modelClass}: ".count($pks).' record(s)');

        return [
            'total_processed' => count($pks),
            'last_processed_id' => empty($pks) ? null : max($pks),
        ];
    }

    /**
     * What a run would do now, without writing anything (counts of decide()):
     * rows to create from the sheet, records to import / update in place /
     * append, and records that agree and only get a baseline.
     *
     * @return array{create: int, import: int, update: int, append: int, baseline: int}
     */
    public function preview(string $modelClass): array
    {
        $plan = $this->decide($modelClass);
        $counts = ['create' => 0, 'import' => 0, 'update' => 0, 'append' => 0, 'baseline' => count($plan['seeds'])];

        foreach ($plan['decisions'] as $decision) {
            $counts[match ($decision['action']) {
                'create', 'import' => $decision['action'],
                default => ($decision['payload']['rowIndex'] ?? null) !== null ? 'update' : 'append',
            }]++;
        }

        return $counts;
    }

    /**
     * Decision phase: read both sides + baseline and resolve the winner per
     * record. Pure (reads only, no writes) so a dispatcher can run it centrally
     * and then fan the resulting plan out to apply jobs.
     *
     * @return array{decisions: array<int, array{pk: int|string, action: string, payload: array, hash: string}>, seeds: array<int|string, array{db: string, sheet: string}>}
     *   - decisions: actionable records. action 'import' -> payload {assoc, hashColumns};
     *     action 'export' -> payload {rowIndex (int|null), appendAt (int|null),
     *     values (columnIndex => cell), headers (list|null: bootstrap an empty sheet)};
     *     action 'create' (sheet row without a key; pk null) -> payload {rowIndex,
     *     raw, assoc (parsed), sheetAssoc (as read), hashColumns}.
     *   - seeds: pk => both sides' hash for records that agree but whose
     *     baseline is missing or stale.
     */
    public function decide(string $modelClass): array
    {
        /** @var SheetSyncable&Model $model */
        $model = new $modelClass;

        $keyColumn = $model->getSheetKeyColumn();
        $headers = $this->resolveHeaders($model);

        if (! in_array($keyColumn, $headers, true)) {
            throw new ImportException(
                "Reconcile requires the key column [{$keyColumn}] in the sheet row of {$modelClass}"
            );
        }

        $values = $this->googleClient->readSheet($model->getSheetIdentifier(), $model->getSheetName());
        $layout = $this->layout($model, $headers, $values[0] ?? [], $keyColumn);
        $layout['nextRow'] = max(count($values), 1) + 1;
        $codec = $this->transformer->forModel($model);

        $sheet = $this->readSheet($model, $values, $layout, $codec);
        $sides = [
            'sheet' => $sheet['keyed'],
            'db' => $this->readDatabase($modelClass, $headers, $layout, $codec),
            'base' => $this->stateManager->baselineFor($modelClass),
            'trashed' => $this->trashedKeys($modelClass),
        ];

        $plan = $this->plan($sides, $headers, $layout, $this->resolveSourceOfTruth($model), $codec);
        array_push($plan['decisions'], ...$this->createDecisions($model, $sheet['keyless'], $layout, $codec));

        return $plan;
    }

    /**
     * Apply phase: execute a chunk of decisions (from {@see decide()}) and store
     * the new baseline for those records. Returns the processed PKs. Safe to run
     * from a queued job.
     *
     * @param  array<int, array{pk: int|string, action: string, payload: array, hash: string}>  $decisions
     * @return array<int, int|string>
     */
    public function applyChunk(string $modelClass, array $decisions): array
    {
        if (empty($decisions)) {
            return [];
        }

        /** @var SheetSyncable $model */
        $model = new $modelClass;

        $toImport = [];
        $exports = ['updates' => [], 'appends' => [], 'headers' => null];
        $toCreate = [];
        $newBaseline = [];
        $pks = [];
        $hashColumns = null;

        foreach ($decisions as $decision) {
            $payload = $decision['payload'];
            if ($decision['action'] === 'create') {
                $toCreate[$payload['rowIndex']] = $payload;

                continue;
            }

            $pk = $decision['pk'];
            $pks[] = $pk;
            // Export: the sheet now holds the DB form. Import: the DB side's
            // form is known once imported (below).
            $newBaseline[$pk] = ['db' => $decision['hash'], 'sheet' => $decision['hash']];

            if ($decision['action'] === 'import') {
                $toImport[$pk] = $payload['assoc'];
                $hashColumns ??= $payload['hashColumns'] ?? null;
            } elseif (($payload['rowIndex'] ?? null) !== null) {
                $exports['updates'][$payload['rowIndex']] = $payload['values'];
            } else {
                $exports['appends'][$payload['appendAt']] = $payload['values'];
                $exports['headers'] ??= $payload['headers'] ?? null;
            }
        }

        $codec = $this->transformer->forModel($model);
        foreach ($this->applyImports($modelClass, $toImport, $codec, $hashColumns) as $pk => $dbHash) {
            $newBaseline[$pk]['db'] = $dbHash;
        }
        $this->applyExports($model, $exports);
        foreach ($this->applyCreates($model, $toCreate, $codec) as $pk => $hashes) {
            $pks[] = $pk;
            $newBaseline[$pk] = $hashes;
        }
        $this->stateManager->storeSnapshots($modelClass, $newBaseline);

        return $pks;
    }

    /**
     * Create records for sheet rows without a key and write only the new key
     * back onto each row: the row is what the person typed (formulas, blanks,
     * spacing) and stays as is. The baseline keeps each side's own form.
     *
     * @param  array<int, array{rowIndex: int, raw: array, assoc: array, sheetAssoc?: array, hashColumns: array<int, string>}>  $toCreate
     * @return array<int|string, array{db: string, sheet: string}>  new pk => baseline
     */
    private function applyCreates(SheetSyncable $model, array $toCreate, DataTransformer $codec): array
    {
        if (empty($toCreate)) {
            return [];
        }

        $headers = $this->resolveHeaders($model);
        $hashes = [];
        $rows = array_map(static fn (array $payload) => ['raw' => $payload['raw'], 'assoc' => $payload['assoc']], $toCreate);

        $keyColumn = $model->getSheetKeyColumn();
        $keyIndex = $model->getSheetKeyColumnIndex();

        $this->importer->createFromSheetRows($model, $rows, function (array $records) use ($model, $headers, $toCreate, $codec, $keyColumn, $keyIndex, &$hashes) {
            $updates = [];
            foreach ($records as $rowIndex => $record) {
                $record = $record->fresh() ?? $record; // DB defaults / casts, as the next run reads it
                $hashColumns = $toCreate[$rowIndex]['hashColumns'];
                $dbHash = $codec->canonicalHash($codec->recordToAssoc($record, $headers), $hashColumns);

                // The row as the next run reads it: unchanged, plus the key.
                $sheetAssoc = $toCreate[$rowIndex]['sheetAssoc'] ?? null;
                if ($sheetAssoc !== null) {
                    $sheetAssoc[$keyColumn] = $this->importer->normalizeKey($record->getKey());
                }

                $updates[$rowIndex] = [$keyIndex => $codec->formatRow([$keyColumn => $record->getKey()], [$keyColumn])[0]];
                $hashes[$record->getKey()] = [
                    'db' => $dbHash,
                    'sheet' => $sheetAssoc !== null ? $codec->canonicalHash($sheetAssoc, $hashColumns) : $dbHash,
                ];
            }

            $this->googleClient->updateRows($model->getSheetIdentifier(), $model->getSheetName(), $updates);
        });

        return $hashes;
    }

    /**
     * Resolve where each model column lives on the sheet and which columns
     * take part in change detection.
     *
     * @return array{columns: array<string, int>, writable: array<string, int>, hash: array<int, string>, headers: array<int, string>|null}
     *   columns: model header => 0-based sheet column (headers present on both sides,
     *            plus the key at getSheetKeyColumnIndex());
     *   writable: the subset written onto an existing row (minus read-only columns);
     *   hash: the headers compared by canonicalHash();
     *   headers: the model headers to write first when the sheet is empty.
     */
    private function layout(SheetSyncable $model, array $headers, array $sheetHeaders, string $keyColumn): array
    {
        $bootstrap = empty($sheetHeaders) ? $headers : null;
        $source = $bootstrap ?? $sheetHeaders;
        $this->importer->assertKeyHeader($model, $source);

        $columns = [];
        foreach ($headers as $header) {
            $position = $header === '' ? false : array_search($header, $source, true);
            if ($position !== false) {
                $columns[$header] = $position;
            }
        }
        // The key lives at its declared position, whatever (if any) its header is.
        $columns[$keyColumn] = $model->getSheetKeyColumnIndex();

        $readOnly = $this->resolveReadOnly($model);
        $ignore = array_merge($this->resolveHashIgnore($model), $readOnly);

        return [
            'columns' => $columns,
            'writable' => array_diff_key($columns, array_flip($readOnly)),
            'hash' => array_values(array_diff(array_keys($columns), $ignore)),
            'headers' => $bootstrap,
        ];
    }

    /**
     * Resolve each record into a decision or a baseline seed.
     *
     * @param  array{sheet: array, db: array, base: array, trashed: array}  $sides
     */
    private function plan(array $sides, array $headers, array $layout, string $sot, DataTransformer $codec): array
    {
        $decisions = [];
        $seeds = [];
        $nextRow = $layout['nextRow'];

        foreach (array_unique(array_merge(array_keys($sides['db']), array_keys($sides['sheet']))) as $pk) {
            $db = $sides['db'][$pk] ?? null;
            $sheet = $sides['sheet'][$pk] ?? null;

            if ($db === null && isset($sides['trashed'][$pk])) {
                continue; // soft-deleted in the DB: leave both sides alone
            }

            $base = $sides['base'][$pk] ?? null;
            $winner = $this->decideWinner($db['hash'] ?? null, $sheet['hash'] ?? null, $base, $sot);

            if ($winner === 'none') {
                $seed = ['db' => $db['hash'], 'sheet' => $sheet['hash']];
                if ($base !== $seed) {
                    $seeds[$pk] = $seed;
                }
            } elseif ($winner === 'sheet') {
                $decisions[] = ['pk' => $pk, 'action' => 'import', 'payload' => ['assoc' => $sheet['assoc'], 'hashColumns' => $layout['hash']], 'hash' => $sheet['hash']];
            } else {
                $payload = $this->exportPayload($db['model'], $headers, $layout, $sheet['rowIndex'] ?? null, $nextRow, $codec, $sheet['assoc'] ?? null);
                $decisions[] = ['pk' => $pk, 'action' => 'export', 'payload' => $payload, 'hash' => $db['hash']];
            }
        }

        return ['decisions' => $decisions, 'seeds' => $seeds];
    }

    /**
     * Cells to write for a db-wins record. A new row gets every mapped column
     * (plus header bootstrap) and the next free row number, so queued chunks
     * stay order-free. An existing row gets only the writable cells whose
     * value differs from what the sheet holds: an unchanged cell (a formula
     * whose value still matches, say) is left as it is.
     *
     * @param  array<string, mixed>|null  $sheetAssoc  the row as read (existing rows)
     */
    private function exportPayload(Model $record, array $headers, array $layout, ?int $rowIndex, int &$nextRow, DataTransformer $codec, ?array $sheetAssoc = null): array
    {
        $columns = $rowIndex === null ? $layout['columns'] : $layout['writable'];
        $assoc = $codec->recordToAssoc($record, $headers);
        $cells = array_combine(
            array_values($columns),
            $codec->formatRow($assoc, array_keys($columns))
        );

        if ($rowIndex !== null && $sheetAssoc !== null) {
            foreach ($columns as $header => $column) {
                if ($codec->canonicalHash($assoc, [$header]) === $codec->canonicalHash($sheetAssoc, [$header])) {
                    unset($cells[$column]);
                }
            }
        }

        return [
            'rowIndex' => $rowIndex,
            'appendAt' => $rowIndex === null ? $nextRow++ : null,
            'values' => $cells,
            'headers' => $rowIndex === null ? $layout['headers'] : null,
        ];
    }

    /**
     * Decide which side wins for a single record (null hash = side missing).
     * Each side is compared with its own form at the last sync, so the two
     * may legitimately differ in normalisation (spacing, 0 vs blank, a
     * formula's value) without counting as a change.
     *
     * @param  array{db: string, sheet: string}|null  $base
     * @return string 'db' | 'sheet' | 'none'
     */
    private function decideWinner(?string $dbHash, ?string $sheetHash, ?array $base, string $sot): string
    {
        if ($dbHash === null || $sheetHash === null) {
            // Present on one side only. No-delete policy: re-propagate to the other.
            return $dbHash !== null ? 'db' : 'sheet';
        }

        if ($dbHash === $sheetHash) {
            return 'none'; // both sides agree (also on a first run without baseline)
        }

        // No baseline: both count as changed.
        $dbChanged = $dbHash !== ($base['db'] ?? null);
        $sheetChanged = $sheetHash !== ($base['sheet'] ?? null);

        if ($dbChanged && $sheetChanged) {
            return $sot; // genuine conflict -> source-of-truth decides
        }

        if (! $dbChanged && ! $sheetChanged) {
            return 'none';
        }

        return $dbChanged ? 'db' : 'sheet';
    }

    /**
     * Index the sheet rows (header row included in $values) by primary key,
     * read from the key cell by position; rows whose key cell is empty are
     * returned apart (candidates for creation).
     *
     * @return array{keyed: array<string, array{rowIndex: int, assoc: array<string, mixed>, hash: string}>, keyless: array<int, array{raw: array<int, mixed>, assoc: array<string, mixed>}>}
     */
    private function readSheet(SheetSyncable $model, array $values, array $layout, DataTransformer $codec): array
    {
        $sheetHeaders = array_shift($values);
        $map = ['keyed' => [], 'keyless' => []];
        if (empty($sheetHeaders) || empty($values)) {
            return $map;
        }

        $keyColumn = $model->getSheetKeyColumn();
        $keyIndex = $model->getSheetKeyColumnIndex();
        foreach ($values as $offset => $row) {
            if ($this->importer->trimRow($row) === []) {
                continue; // blank line
            }

            // +2: one for the header row shifted off, one for 1-based rows.
            $rowIndex = $offset + 2;
            $pk = $this->importer->normalizeKey($row[$keyIndex] ?? null);
            $assoc = $this->rowToAssoc($sheetHeaders, $row);
            $assoc[$keyColumn] = $pk;

            if ($pk === null) {
                $map['keyless'][$rowIndex] = ['raw' => $row, 'assoc' => $assoc];

                continue;
            }

            $map['keyed'][(string) $pk] = [
                'rowIndex' => $rowIndex,
                'assoc' => $assoc,
                'hash' => $codec->canonicalHash($assoc, $layout['hash']),
            ];
        }

        return $map;
    }

    /**
     * Sheet rows without a key that map to a record: create them in the DB and
     * write the new key back (applied by applyChunk()).
     */
    private function createDecisions(SheetSyncable $model, array $keyless, array $layout, DataTransformer $codec): array
    {
        $decisions = [];
        foreach ($keyless as $rowIndex => $row) {
            $assoc = $codec->parseRow($row['assoc']);
            if (! $this->importer->canCreateFromSheetRow($model, $assoc)) {
                continue; // the model does not take it (separator, total, ...)
            }

            $decisions[] = ['pk' => null, 'action' => 'create', 'hash' => null, 'payload' => [
                'rowIndex' => $rowIndex,
                'raw' => $row['raw'],
                'assoc' => $assoc,
                'sheetAssoc' => $row['assoc'],
                'hashColumns' => $layout['hash'],
            ]];
        }

        return $decisions;
    }

    /**
     * Read the model table into a pk => {model, hash} map.
     *
     * @return array<string, array{model: Model, hash: string}>
     */
    private function readDatabase(string $modelClass, array $headers, array $layout, DataTransformer $codec): array
    {
        $model = new $modelClass;
        $chunk = method_exists($model, 'getBatchSize') ? $model->getBatchSize() : config('syncro-sheet.defaults.batch_size', 100);

        // lazyById(), not cursor(): it pages with get(), so the model's $with
        // eager loads apply and toSheetRow() does not trigger N+1 queries.
        $map = [];
        foreach ($modelClass::query()->lazyById(max((int) $chunk, 1), $model->getKeyName()) as $record) {
            $assoc = $codec->recordToAssoc($record, $headers);
            $map[(string) $record->getKey()] = [
                'model' => $record,
                'hash' => $codec->canonicalHash($assoc, $layout['hash']),
            ];
        }

        return $map;
    }

    /**
     * Primary keys of soft-deleted records (empty when the model has no soft deletes).
     *
     * @return array<string, true>
     */
    private function trashedKeys(string $modelClass): array
    {
        if (! in_array(SoftDeletes::class, class_uses_recursive($modelClass), true)) {
            return [];
        }

        return $modelClass::onlyTrashed()
            ->pluck((new $modelClass)->getKeyName())
            ->mapWithKeys(fn ($key) => [(string) $key => true])
            ->all();
    }

    /**
     * Upsert the sheet-wins records into the database.
     *
     * @param  array<string, array<string, mixed>>  $toImport  pk => sheet assoc
     * @param  array<int, string>|null  $hashColumns  null (a plan queued before hashColumns existed): no DB hashes
     * @return array<string, string>  pk => content hash of the imported record (the DB side's new baseline)
     */
    private function applyImports(string $modelClass, array $toImport, DataTransformer $codec, ?array $hashColumns): array
    {
        if (empty($toImport)) {
            return [];
        }

        foreach ($toImport as $pk => $assoc) {
            $this->importer->upsertRow($modelClass, $pk, $codec->parseRow($assoc));
        }

        $this->importer->syncKeySequence($modelClass);

        if ($hashColumns === null) {
            return [];
        }

        $headers = $this->resolveHeaders(new $modelClass);
        $hashes = [];
        foreach ($modelClass::query()->whereKey(array_keys($toImport))->get() as $record) {
            $hashes[(string) $record->getKey()] = $codec->canonicalHash($codec->recordToAssoc($record, $headers), $hashColumns);
        }

        return $hashes;
    }

    /**
     * Write the db-wins records back onto the sheet: cell-level updates of
     * existing rows in one batch, then new rows appended after the last row.
     *
     * @param  array{updates: array<int, array<int, mixed>>, appends: array<int, array<int, mixed>>, headers: array<int, string>|null}  $exports
     */
    private function applyExports(SheetSyncable $model, array $exports): void
    {
        $id = $model->getSheetIdentifier();
        $name = $model->getSheetName();

        if ($exports['headers'] !== null) {
            $this->googleClient->setHeaders($id, $name, $exports['headers']);
        }

        if (! empty($exports['updates'])) {
            $this->googleClient->updateRows($id, $name, $exports['updates']);
        }

        if (! empty($exports['appends'])) {
            ksort($exports['appends']);
            $rows = array_map($this->cellsToList(...), $exports['appends']);
            $this->googleClient->appendRows($id, $name, array_values($rows), array_key_first($exports['appends']) - 1);
        }
    }

    /**
     * columnIndex => value -> list starting at column A, blanks in the gaps.
     */
    private function cellsToList(array $cells): array
    {
        return empty($cells) ? [] : array_replace(array_fill(0, max(array_keys($cells)) + 1, ''), $cells);
    }

    private function resolveHeaders(SheetSyncable $model): array
    {
        if (method_exists($model, 'defaultSheetHeaders')) {
            return $model->defaultSheetHeaders();
        }

        return array_keys($model->toSheetRow());
    }

    private function resolveHashIgnore(SheetSyncable $model): array
    {
        if (method_exists($model, 'syncHashIgnore')) {
            return $model->syncHashIgnore();
        }

        return config('syncro-sheet.defaults.reconcile.hash_ignore', ['updated_at']);
    }

    /**
     * Sheet-managed columns (e.g. formulas): never overwritten on an existing
     * row, still written on a new row, and excluded from change detection.
     */
    private function resolveReadOnly(SheetSyncable $model): array
    {
        return method_exists($model, 'syncReadOnlyColumns') ? $model->syncReadOnlyColumns() : [];
    }

    private function resolveSourceOfTruth(SheetSyncable $model): string
    {
        $sot = method_exists($model, 'getSyncSourceOfTruth')
            ? $model->getSyncSourceOfTruth()
            : config('syncro-sheet.defaults.source_of_truth', 'db');

        return $sot === 'sheet' ? 'sheet' : 'db';
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
