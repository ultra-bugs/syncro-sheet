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
     * Decision phase: read both sides + baseline and resolve the winner per
     * record. Pure (reads only, no writes) so a dispatcher can run it centrally
     * and then fan the resulting plan out to apply jobs.
     *
     * @return array{decisions: array<int, array{pk: int|string, action: string, payload: array, hash: string}>, seeds: array<int|string, string>}
     *   - decisions: actionable records. action 'import' -> payload {assoc};
     *     action 'export' -> payload {rowIndex (int|null), appendAt (int|null),
     *     values (columnIndex => cell), headers (list|null: bootstrap an empty sheet)}.
     *   - seeds: pk => hash for records that already agree but have no baseline yet.
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

        $sides = [
            'sheet' => $this->readSheet($values, $keyColumn, $layout),
            'db' => $this->readDatabase($modelClass, $headers, $layout),
            'base' => $this->stateManager->baselineFor($modelClass),
            'trashed' => $this->trashedKeys($modelClass),
        ];

        return $this->plan($sides, $headers, $layout, $this->resolveSourceOfTruth($model));
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
        $newBaseline = [];
        $pks = [];

        foreach ($decisions as $decision) {
            $pk = $decision['pk'];
            $pks[] = $pk;
            $newBaseline[$pk] = $decision['hash'];
            $payload = $decision['payload'];

            if ($decision['action'] === 'import') {
                $toImport[$pk] = $payload['assoc'];
            } elseif (($payload['rowIndex'] ?? null) !== null) {
                $exports['updates'][$payload['rowIndex']] = $payload['values'];
            } else {
                $exports['appends'][$payload['appendAt']] = $payload['values'];
                $exports['headers'] ??= $payload['headers'] ?? null;
            }
        }

        $this->applyImports($modelClass, $toImport);
        $this->applyExports($model, $exports);
        $this->stateManager->storeSnapshots($modelClass, $newBaseline);

        return $pks;
    }

    /**
     * Resolve where each model column lives on the sheet and which columns
     * take part in change detection.
     *
     * @return array{columns: array<string, int>, writable: array<string, int>, hash: array<int, string>, headers: array<int, string>|null}
     *   columns: model header => 0-based sheet column (headers present on both sides);
     *   writable: the subset written onto an existing row (minus read-only columns);
     *   hash: the headers compared by canonicalHash();
     *   headers: the model headers to write first when the sheet is empty.
     */
    private function layout(SheetSyncable $model, array $headers, array $sheetHeaders, string $keyColumn): array
    {
        $bootstrap = empty($sheetHeaders) ? $headers : null;
        $source = $bootstrap ?? $sheetHeaders;

        if (! in_array($keyColumn, $source, true)) {
            throw new ImportException(
                "Sheet is missing the key column [{$keyColumn}] required to reconcile ".get_class($model)
            );
        }

        $columns = [];
        foreach ($headers as $header) {
            $position = $header === '' ? false : array_search($header, $source, true);
            if ($position !== false) {
                $columns[$header] = $position;
            }
        }

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
    private function plan(array $sides, array $headers, array $layout, string $sot): array
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

            $winner = $this->decideWinner($db['hash'] ?? null, $sheet['hash'] ?? null, $sides['base'][$pk] ?? null, $sot);

            if ($winner === 'none') {
                if (! isset($sides['base'][$pk]) || $sides['base'][$pk] !== $db['hash']) {
                    $seeds[$pk] = $db['hash'];
                }
            } elseif ($winner === 'sheet') {
                $decisions[] = ['pk' => $pk, 'action' => 'import', 'payload' => ['assoc' => $sheet['assoc']], 'hash' => $sheet['hash']];
            } else {
                $payload = $this->exportPayload($db['model'], $headers, $layout, $sheet['rowIndex'] ?? null, $nextRow);
                $decisions[] = ['pk' => $pk, 'action' => 'export', 'payload' => $payload, 'hash' => $db['hash']];
            }
        }

        return ['decisions' => $decisions, 'seeds' => $seeds];
    }

    /**
     * Cells to write for a db-wins record: only the writable columns of an
     * existing row; every mapped column (plus header bootstrap) for a new row,
     * which gets the next free row number so queued chunks stay order-free.
     */
    private function exportPayload(Model $record, array $headers, array $layout, ?int $rowIndex, int &$nextRow): array
    {
        $columns = $rowIndex === null ? $layout['columns'] : $layout['writable'];
        $assoc = $this->transformer->toAssoc($record->toSheetRow(), $headers);
        $cells = array_combine(
            array_values($columns),
            $this->transformer->formatRow($assoc, array_keys($columns))
        );

        return [
            'rowIndex' => $rowIndex,
            'appendAt' => $rowIndex === null ? $nextRow++ : null,
            'values' => $cells,
            'headers' => $rowIndex === null ? $layout['headers'] : null,
        ];
    }

    /**
     * Decide which side wins for a single record (null hash = side missing).
     *
     * @return string 'db' | 'sheet' | 'none'
     */
    private function decideWinner(?string $dbHash, ?string $sheetHash, ?string $base, string $sot): string
    {
        if ($dbHash === null || $sheetHash === null) {
            // Present on one side only. No-delete policy: re-propagate to the other.
            return $dbHash !== null ? 'db' : 'sheet';
        }

        if ($dbHash === $sheetHash) {
            return 'none'; // both sides agree (also on a first run without baseline)
        }

        $dbChanged = $dbHash !== $base;
        $sheetChanged = $sheetHash !== $base;

        if ($dbChanged && $sheetChanged) {
            return $sot; // genuine conflict -> source-of-truth decides
        }

        return $dbChanged ? 'db' : 'sheet';
    }

    /**
     * Index the sheet rows (header row included in $values) by primary key.
     *
     * @return array<string, array{rowIndex: int, assoc: array<string, mixed>, hash: string}>
     */
    private function readSheet(array $values, string $keyColumn, array $layout): array
    {
        $sheetHeaders = array_shift($values);
        if (empty($sheetHeaders) || empty($values)) {
            return [];
        }

        $map = [];
        foreach ($values as $offset => $row) {
            $assoc = $this->rowToAssoc($sheetHeaders, $row);
            $pk = $assoc[$keyColumn] ?? null;
            if ($pk === null || $pk === '') {
                continue;
            }

            $map[(string) $pk] = [
                // +2: one for the header row shifted off, one for 1-based rows.
                'rowIndex' => $offset + 2,
                'assoc' => $assoc,
                'hash' => $this->transformer->canonicalHash($assoc, $layout['hash']),
            ];
        }

        return $map;
    }

    /**
     * Read the model table into a pk => {model, hash} map.
     *
     * @return array<string, array{model: Model, hash: string}>
     */
    private function readDatabase(string $modelClass, array $headers, array $layout): array
    {
        $model = new $modelClass;
        $chunk = method_exists($model, 'getBatchSize') ? $model->getBatchSize() : config('syncro-sheet.defaults.batch_size', 100);

        // lazyById(), not cursor(): it pages with get(), so the model's $with
        // eager loads apply and toSheetRow() does not trigger N+1 queries.
        $map = [];
        foreach ($modelClass::query()->lazyById(max((int) $chunk, 1), $model->getKeyName()) as $record) {
            $assoc = $this->transformer->toAssoc($record->toSheetRow(), $headers);
            $map[(string) $record->getKey()] = [
                'model' => $record,
                'hash' => $this->transformer->canonicalHash($assoc, $layout['hash']),
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
     */
    private function applyImports(string $modelClass, array $toImport): void
    {
        if (empty($toImport)) {
            return;
        }

        foreach ($toImport as $pk => $assoc) {
            $this->importer->upsertRow($modelClass, $pk, $this->transformer->parseRow($assoc));
        }

        $this->importer->syncKeySequence($modelClass);
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
