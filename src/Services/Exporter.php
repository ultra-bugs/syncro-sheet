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

use Illuminate\Database\Eloquent\Builder;
use Zuko\SyncroSheet\Contracts\SheetExportable;
use Zuko\SyncroSheet\Models\SyncState;

/**
 * Export engine: pushes Eloquent records TO a Google Sheet (DB -> Sheet) in
 * memory-efficient chunks. Counterpart of {@see Importer}.
 */
class Exporter
{
    public function __construct(
        private readonly StateManager $stateManager,
        private readonly DataTransformer $transformer,
        private readonly GoogleClient $googleClient,
        private readonly SyncLogger $logger
    ) {
    }

    /**
     * Export a full sync in batches
     */
    public function export(string $modelClass, SyncState $syncState, ?string $writeMode = null): array
    {
        $model = new $modelClass;
        $writeMode = $writeMode ?? $syncState->write_mode;
        // Handle replace mode by clearing sheet first
        if ($writeMode === 'replace') {
            $this->googleClient->clearSheet(
                $model->getSheetIdentifier(),
                $model->getSheetName()
            );
        }

        return $this->appendRecords($model, $this->buildFullSyncQuery($modelClass, $syncState), $syncState);
    }

    /**
     * Export a partial sync for specific records
     */
    public function exportPartial(string $modelClass, array $recordIds, SyncState $syncState): array
    {
        return $this->appendRecords(new $modelClass, $this->buildPartialSyncQuery($modelClass, $recordIds), $syncState);
    }

    /**
     * Append the records of $query in chunks, each value placed under its
     * header's column on the sheet, after the last row in use.
     */
    private function appendRecords(SheetExportable $model, Builder $query, SyncState $syncState): array
    {
        $batchSize = method_exists($model, 'getBatchSize')
            ? $model->getBatchSize()
            : config('syncro-sheet.defaults.batch_size');

        [$sheetHeaders, $lastRow] = $this->ensureHeaders($model);
        $modelHeaders = $this->modelHeaders($model);
        $totalProcessed = 0;
        $lastProcessedId = null;

        // chunkById, not chunk(): every processed batch is recorded in
        // sync_entries, which the full-sync query filters on, so offset paging
        // would skip records.
        $query->chunkById($batchSize, function ($records) use ($model, $sheetHeaders, $modelHeaders, $syncState, &$lastRow, &$totalProcessed, &$lastProcessedId) {
            $rows = $records->map(fn ($record) => $this->placeOnSheet($record->toSheetRow(), $modelHeaders, $sheetHeaders))->all();

            $this->googleClient->appendRows($model->getSheetIdentifier(), $model->getSheetName(), $rows, $lastRow);
            $lastRow += count($rows);

            $processedIds = $records->pluck($model->getKeyName())->toArray();
            $this->stateManager->recordBatchSync($syncState, $processedIds);

            $totalProcessed += count($records);
            $lastProcessedId = $records->last()->{$model->getKeyName()};

            $this->logger->info("Processed batch of {$records->count()} records for {$syncState->model_class}");
        }, $model->getKeyName());

        return [
            'total_processed' => $totalProcessed,
            'last_processed_id' => $lastProcessedId,
        ];
    }

    /**
     * One toSheetRow() (positional or header-keyed) -> list aligned to the
     * sheet's header row; columns the model does not know stay blank.
     *
     * @return array<int, mixed>
     */
    private function placeOnSheet(array $row, array $modelHeaders, array $sheetHeaders): array
    {
        $assoc = $this->transformer->toAssoc($row, $modelHeaders);

        return $this->transformer->formatRow($assoc, array_map(
            static fn ($header) => array_key_exists($header, $assoc) ? $header : '',
            $sheetHeaders
        ));
    }

    private function getModelAndKeyName($modelClass): array
    {
        $model = new $modelClass;

        return [$model, $model->getKeyName()];
    }

    private function modelHeaders(SheetExportable $model): array
    {
        return method_exists($model, 'defaultSheetHeaders')
            ? $model->defaultSheetHeaders()
            : array_keys($model->toSheetRow());
    }

    private function buildFullSyncQuery(string $modelClass, SyncState $syncState): Builder
    {
        $model = new $modelClass;
        $keyName = $model->getKeyName();
        $query = $modelClass::query();

        if ($syncState->last_processed_id) {
            $query->where($keyName, '>', $syncState->last_processed_id);
        }

        // Get records that haven't been synced in the last 7 days
        $query->whereNotExists(function ($query) use ($modelClass, $keyName) {
            $query->from('sync_entries')
                ->where('model_class', $modelClass)
                ->where('synced_at', '>', now()->subDays(7))
                ->whereColumn('record_id', $keyName);
        });

        return $query->orderBy($keyName);
    }

    private function buildPartialSyncQuery(string $modelClass, array $recordIds): Builder
    {
        [$model, $keyName] = $this->getModelAndKeyName($modelClass);

        return $modelClass::query()
            ->whereIn($keyName, $recordIds)
            ->orderBy($keyName);
    }

    /**
     * Read the sheet once: its header row (written from the model when the
     * sheet is empty) and the number of the last row in use.
     *
     * @return array{0: array<int, string>, 1: int}
     */
    private function ensureHeaders(SheetExportable $model): array
    {
        $values = $this->googleClient->readSheet(
            $model->getSheetIdentifier(),
            $model->getSheetName()
        );

        if (! empty($values[0])) {
            return [$values[0], count($values)];
        }

        $expectedHeaders = $this->modelHeaders($model);

        $this->googleClient->setHeaders(
            $model->getSheetIdentifier(),
            $model->getSheetName(),
            $expectedHeaders
        );

        return [$expectedHeaders, max(count($values), 1)];
    }
}
