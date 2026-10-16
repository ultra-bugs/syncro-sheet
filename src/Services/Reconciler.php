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

use Zuko\SyncroSheet\Contracts\SheetImportable;
use Zuko\SyncroSheet\Exceptions\ImportException;
use Zuko\SyncroSheet\Models\SyncState;

/**
 * Two-way reconcile engine (Sheet <-> DB) for bidirectional models.
 *
 * Uses a per-record content-hash baseline ({@see SnapshotManager}) to decide
 * which side actually changed since the last sync; genuine conflicts (both
 * sides changed) are resolved by a fixed source-of-truth. Upsert semantics on
 * the DB side; row-level writes on the sheet side. Nothing is deleted.
 */
class Reconciler
{
    public function __construct(
        private readonly SnapshotManager $snapshots,
        private readonly DataTransformer $transformer,
        private readonly GoogleClient $googleClient,
        private readonly StateManager $stateManager,
        private readonly SyncLogger $logger
    ) {
    }

    public function reconcile(string $modelClass, SyncState $syncState): array
    {
        /** @var SheetImportable $model */
        $model = new $modelClass;

        $keyColumn = $model->getSheetKeyColumn();
        $modelKey = $model->getKeyName();
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

        $baseline = $this->snapshots->baselineFor($modelClass);

        $toImport = [];     // pk => sheet assoc   (sheet wins -> upsert DB)
        $rowUpdates = [];   // rowIndex => values  (db wins, row already in sheet)
        $rowAppends = [];   // assoc rows          (db wins, new to sheet)
        $newBaseline = [];  // pk => content hash
        $processedPks = [];

        $allPks = array_unique(array_merge(array_keys($dbByPk), array_keys($sheetByPk)));

        foreach ($allPks as $pk) {
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
                // Content already agrees; just make sure a baseline exists.
                if ($base === null) {
                    $newBaseline[$pk] = $dbByPk[$pk]['hash'];
                }

                continue;
            }

            if ($winner === 'sheet') {
                $toImport[$pk] = $sheetByPk[$pk]['assoc'];
                $newBaseline[$pk] = $sheetByPk[$pk]['hash'];
            } else { // db wins
                $values = $this->transformer->formatRow($dbByPk[$pk]['model']->toSheetRow(), $headers);
                if ($inSheet) {
                    $rowUpdates[$sheetByPk[$pk]['rowIndex']] = $values;
                } else {
                    $rowAppends[] = array_combine($headers, $values);
                }
                $newBaseline[$pk] = $dbByPk[$pk]['hash'];
            }

            $processedPks[] = $pk;
        }

        $this->applyImports($modelClass, $model, $modelKey, $keyColumn, $toImport);
        $this->applyExports($model, $rowUpdates, $rowAppends);

        $this->snapshots->storeMany($modelClass, $newBaseline);

        if (! empty($processedPks)) {
            $this->stateManager->recordBatchSync($syncState, $processedPks);
        }

        $this->logger->info("Reconciled {$modelClass}: ".count($processedPks).' record(s)');

        return [
            'total_processed' => count($processedPks),
            'last_processed_id' => empty($processedPks) ? null : max($processedPks),
        ];
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
    private function readSheet(SheetImportable $model, string $keyColumn, array $headers, array $ignore): array
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
        SheetImportable $model,
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
    private function applyExports(SheetImportable $model, array $rowUpdates, array $rowAppends): void
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

    private function resolveHeaders(SheetImportable $model): array
    {
        if (method_exists($model, 'defaultSheetHeaders')) {
            return $model->defaultSheetHeaders();
        }

        return array_keys($model->toSheetRow());
    }

    private function resolveHashIgnore(SheetImportable $model): array
    {
        if (method_exists($model, 'syncHashIgnore')) {
            return $model->syncHashIgnore();
        }

        return config('syncro-sheet.defaults.reconcile.hash_ignore', ['updated_at']);
    }

    private function resolveSourceOfTruth(SheetImportable $model): string
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
