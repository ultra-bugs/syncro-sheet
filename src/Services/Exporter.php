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
        $batchSize = method_exists($model, 'getBatchSize')
            ? $model->getBatchSize()
            : config('syncro-sheet.defaults.batch_size');
        $writeMode = $writeMode ?? $syncState->write_mode;
        // Handle replace mode by clearing sheet first
        if ($writeMode === 'replace') {
            $this->googleClient->clearSheet(
                $model->getSheetIdentifier(),
                $model->getSheetName()
            );
        }

        // First, ensure headers are set up
        $headers = $this->ensureHeaders($model);

        $query = $this->buildFullSyncQuery($modelClass, $syncState);
        $totalProcessed = 0;
        $lastProcessedId = null;

        $query->chunk($batchSize, function ($records) use ($model, $headers, $syncState, &$totalProcessed, &$lastProcessedId) {
            $rows = $this->transformer->transformBatch($records);

            if (! empty($rows)) {
                // Transform to associative arrays with headers
                $rowsWithHeaders = collect($rows)->map(function ($row) use ($headers) {
                    return array_combine($headers, $row);
                })->toArray();
                $this->googleClient->appendWithHeaders(
                    $model->getSheetIdentifier(),
                    $model->getSheetName(),
                    $rowsWithHeaders
                );
            }

            $processedIds = $records->pluck($model->getKeyName())->toArray();
            $this->stateManager->recordBatchSync($syncState, $processedIds);

            $totalProcessed += count($records);
            $lastProcessedId = $records->last()->{$model->getKeyName()};

            $this->logger->info("Processed batch of {$records->count()} records for {$syncState->model_class}");
        });

        return [
            'total_processed' => $totalProcessed,
            'last_processed_id' => $lastProcessedId,
        ];
    }

    private function getModelAndKeyName($modelClass): array
    {
        $model = new $modelClass;

        return [$model, $model->getKeyName()];
    }

    /**
     * Export a partial sync for specific records
     */
    public function exportPartial(string $modelClass, array $recordIds, SyncState $syncState): array
    {
        $model = new $modelClass;
        $batchSize = method_exists($model, 'getBatchSize')
            ? $model->getBatchSize()
            : config('syncro-sheet.defaults.batch_size');

        $query = $this->buildPartialSyncQuery($modelClass, $recordIds);
        $totalProcessed = 0;
        $lastProcessedId = null;

        $query->lazy()->chunk($batchSize)->each(function ($records) use ($model, $syncState, &$totalProcessed, &$lastProcessedId) {
            if ($records->isEmpty()) {
                return true;
            }

            $rows = $this->transformer->transformBatch($records);

            if (! empty($rows)) {
                $this->googleClient->writeBatch(
                    $records->first()->getSheetIdentifier(),
                    $records->first()->getSheetName(),
                    $rows
                );
            }

            $processedIds = $records->pluck($model->getKeyName())->toArray();
            $this->stateManager->recordBatchSync($syncState, $processedIds);

            $totalProcessed += count($records);
            $lastProcessedId = $records->last()->{$model->getKeyName()};

            $this->logger->info("Processed partial batch of {$records->count()} records for {$syncState->model_class}");

            return true;
        });

        return [
            'total_processed' => $totalProcessed,
            'last_processed_id' => $lastProcessedId,
        ];
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

    private function ensureHeaders(SheetExportable $model): array
    {
        // Get current headers from sheet
        $currentHeaders = $this->googleClient->getHeaders(
            $model->getSheetIdentifier(),
            $model->getSheetName()
        );

        if (! empty($currentHeaders)) {
            return $currentHeaders;
        }

        // Get headers in order of preference
        $expectedHeaders = method_exists($model, 'defaultSheetHeaders')
            ? $model->defaultSheetHeaders()
            : array_keys($model->toSheetRow());

        $this->googleClient->setHeaders(
            $model->getSheetIdentifier(),
            $model->getSheetName(),
            $expectedHeaders
        );

        return $expectedHeaders;
    }
}
