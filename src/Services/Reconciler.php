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

use Zuko\SyncroSheet\Contracts\SheetSyncable;
use Zuko\SyncroSheet\Exceptions\ImportException;
use Zuko\SyncroSheet\Models\SyncState;

/**
 * Two-way reconcile engine (Sheet <-> DB) for bidirectional models.
 *
 * Uses a per-record content-hash baseline (via {@see StateManager}) to decide
 * which side actually changed since the last sync; genuine conflicts (both
 * sides changed) are resolved by a fixed source-of-truth. Upsert semantics on
 * the DB side; row-level writes on the sheet side. Nothing is deleted.
 */
class Reconciler
{
    public function __construct(
        private readonly DataTransformer $transformer,
        private readonly GoogleClient $googleClient,
        private readonly StateManager $stateManager,
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
     *     action 'export' -> payload {rowIndex (int|null), values (ordered list)}.
     *   - seeds: pk => hash for records that already agree but have no baseline yet.
     */
    public function decide(string $modelClass): array
    {
        /** @var SheetSyncable $model */
        $model = new $modelClass;

        $keyColumn = $model->getSheetKeyColumn();
        $headers = $this->resolveHeaders($model);
        $ignore = $this->resolveHashIgnore($model);
        $sot = $this->resolveSourceOfTruth($model);

        if (! in_array($keyColumn, $headers, true)) {
            throw new ImportException(
                "Reconcile requires the key column [{$keyColumn}] in the sheet row of {$modelClass}"
            );
        }

        $sheetByPk = $this->readSheet($model, $keyColumn, $headers, $ignore);
        $dbByPk = $this->readDatabase($modelClass, $headers, $ignore);
        $baseline = $this->stateManager->baselineFor($modelClass);

        $decisions = [];
        $seeds = [];

        foreach (array_unique(array_merge(array_keys($dbByPk), array_keys($sheetByPk))) as $pk) {
            $inDb = isset($dbByPk[$pk]);
            $inSheet = isset($sheetByPk[$pk]);
            $base = $baseline[$pk] ?? null;

            $winner = $this->decideWinner(
                $inDb,
                $inSheet,
                $inDb ? $dbByPk[$pk]['hash'] : null,
                $inSheet ? $sheetByPk[$pk]['hash'] : null,
                $base,
                $sot
            );

            if ($winner === 'none') {
                if ($base === null) {
                    $seeds[$pk] = $dbByPk[$pk]['hash'];
                }

                continue;
            }

            if ($winner === 'sheet') {
                $decisions[] = [
                    'pk' => $pk,
                    'action' => 'import',
                    'payload' => ['assoc' => $sheetByPk[$pk]['assoc']],
                    'hash' => $sheetByPk[$pk]['hash'],
                ];
            } else { // db wins
                $decisions[] = [
                    'pk' => $pk,
                    'action' => 'export',
                    'payload' => [
                        'rowIndex' => $inSheet ? $sheetByPk[$pk]['rowIndex'] : null,
                        'values' => $this->transformer->formatRow($dbByPk[$pk]['model']->toSheetRow(), $headers),
                    ],
                    'hash' => $dbByPk[$pk]['hash'],
                ];
            }
        }

        return ['decisions' => $decisions, 'seeds' => $seeds];
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
        $modelKey = $model->getKeyName();
        $keyColumn = $model->getSheetKeyColumn();
        $headers = $this->resolveHeaders($model);

        $toImport = [];
        $rowUpdates = [];
        $rowAppends = [];
        $newBaseline = [];
        $pks = [];

        foreach ($decisions as $decision) {
            $pk = $decision['pk'];
            $pks[] = $pk;
            $newBaseline[$pk] = $decision['hash'];

            if ($decision['action'] === 'import') {
                $toImport[$pk] = $decision['payload']['assoc'];
            } elseif ($decision['payload']['rowIndex'] !== null) {
                $rowUpdates[$decision['payload']['rowIndex']] = $decision['payload']['values'];
            } else {
                $rowAppends[] = array_combine($headers, $decision['payload']['values']);
            }
        }

        $this->applyImports($modelClass, $model, $modelKey, $keyColumn, $toImport);
        $this->applyExports($model, $rowUpdates, $rowAppends);
        $this->stateManager->storeSnapshots($modelClass, $newBaseline);

        return $pks;
    }

    /**
     * Decide which side wins for a single record.
     *
     * @return string 'db' | 'sheet' | 'none'
     */
    private function decideWinner(
        bool $inDb,
        bool $inSheet,
        ?string $dbHash,
        ?string $sheetHash,
        ?string $base,
        string $sot
    ): string {
        if ($inDb && $inSheet) {
            $dbChanged = $dbHash !== $base;
            $sheetChanged = $sheetHash !== $base;

            if ($dbChanged && $sheetChanged) {
                return $sot; // genuine conflict -> source-of-truth decides
            }
            if ($dbChanged) {
                return 'db';
            }
            if ($sheetChanged) {
                return 'sheet';
            }

            return 'none';
        }

        // Present on one side only. No-delete policy: re-propagate to the other.
        return $inDb ? 'db' : 'sheet';
    }

    /**
     * Read the sheet into a pk => {rowIndex, assoc, hash} map.
     *
     * @return array<string, array{rowIndex: int, assoc: array<string, mixed>, hash: string}>
     */
    private function readSheet(SheetSyncable $model, string $keyColumn, array $headers, array $ignore): array
    {
        $values = $this->googleClient->readSheet(
            $model->getSheetIdentifier(),
            $model->getSheetName()
        );

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
                'hash' => $this->transformer->canonicalHash($assoc, $headers, $ignore),
            ];
        }

        return $map;
    }

    /**
     * Read the model table into a pk => {model, hash} map.
     *
     * @return array<string, array{model: \Illuminate\Database\Eloquent\Model, hash: string}>
     */
    private function readDatabase(string $modelClass, array $headers, array $ignore): array
    {
        $map = [];
        foreach ($modelClass::query()->cursor() as $record) {
            $map[(string) $record->getKey()] = [
                'model' => $record,
                'hash' => $this->transformer->canonicalHash($record->toSheetRow(), $headers, $ignore),
            ];
        }

        return $map;
    }

    /**
     * Upsert the sheet-wins records into the database.
     *
     * @param  array<string, array<string, mixed>>  $toImport  pk => sheet assoc
     */
    private function applyImports(
        string $modelClass,
        SheetSyncable $model,
        string $modelKey,
        string $keyColumn,
        array $toImport
    ): void {
        foreach ($toImport as $pk => $assoc) {
            $attributes = $model->fromSheetRow($this->transformer->parseRow($assoc));
            unset($attributes[$keyColumn], $attributes[$modelKey]);

            $modelClass::updateOrCreate([$modelKey => $pk], $attributes);
        }
    }

    /**
     * Write the db-wins records back onto the sheet.
     *
     * @param  array<int, array<int, mixed>>       $rowUpdates  rowIndex => values
     * @param  array<int, array<string, mixed>>    $rowAppends  assoc rows
     */
    private function applyExports(SheetSyncable $model, array $rowUpdates, array $rowAppends): void
    {
        if (! empty($rowUpdates)) {
            $this->googleClient->updateRows(
                $model->getSheetIdentifier(),
                $model->getSheetName(),
                $rowUpdates
            );
        }

        if (! empty($rowAppends)) {
            $this->googleClient->appendWithHeaders(
                $model->getSheetIdentifier(),
                $model->getSheetName(),
                $rowAppends
            );
        }
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
