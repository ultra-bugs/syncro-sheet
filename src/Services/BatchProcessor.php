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
use Illuminate\Support\Collection;
use Zuko\SyncroSheet\Models\SyncState;
use Zuko\SyncroSheet\Contracts\SheetSyncable;
use Zuko\SyncroSheet\Exceptions\SyncException;

class BatchProcessor
{
    public function __construct(
        private readonly StateManager $stateManager,
        private readonly DataTransformer $transformer,
        private readonly GoogleClient $googleClient,
        private readonly SyncLogger $logger
    ) {}

    /**
     * Process full sync in batches
     */
    public function process(string $modelClass, SyncState $syncState): array
    {
        $model = new $modelClass;
        $batchSize = $model->getBatchSize() ?? config('syncro-sheet.defaults.batch_size');
        
        // First, ensure headers are set up
        $headers = $this->ensureHeaders($model);
        
        $query = $this->buildFullSyncQuery($modelClass, $syncState);
        $totalProcessed = 0;
        $lastProcessedId = null;

        $query->chunk($batchSize, function ($records) use ($model, $headers, $syncState, &$totalProcessed, &$lastProcessedId) {
            $rows = $this->transformer->transformBatch($records);
            
            if (!empty($rows)) {
                // Transform to associative arrays with headers
                $rowsWithHeaders = $rows->map(function($row) use ($headers) {
                    return array_combine($headers, $row);
                })->toArray();

                $this->googleClient->appendWithHeaders(
                    $model->getSheetIdentifier(),
                    $model->getSheetName(),
                    $rowsWithHeaders
                );
            }

            $processedIds = $records->pluck('id')->toArray();
            $this->stateManager->recordBatchSync($syncState, $processedIds);
            
            $totalProcessed += count($records);
            $lastProcessedId = $records->last()->id;
            
            $this->logger->info("Processed batch of {$records->count()} records for {$syncState->model_class}");
        });

        return [
            'total_processed' => $totalProcessed,
            'last_processed_id' => $lastProcessedId
        ];
    }

    /**
     * Process partial sync for specific records
     */
    public function processPartial(string $modelClass, array $recordIds, SyncState $syncState): array
    {
        $model = new $modelClass;
        $batchSize = $model->getBatchSize() ?? config('syncro-sheet.defaults.batch_size');
        
        $query = $this->buildPartialSyncQuery($modelClass, $recordIds);
        $totalProcessed = 0;

        foreach (array_chunk($recordIds, $batchSize) as $batchIds) {
            $records = $query->whereIn('id', $batchIds)->get();
            
            if ($records->isEmpty()) {
                continue;
            }

            $rows = $this->transformer->transformBatch($records);
            
            if (!empty($rows)) {
                $this->googleClient->writeBatch(
                    $records->first()->getSheetIdentifier(),
                    $records->first()->getSheetName(),
                    $rows
                );
            }

            $this->stateManager->recordBatchSync($syncState, $batchIds);
            $totalProcessed += count($records);
            
            $this->logger->info("Processed partial batch of {$records->count()} records for {$syncState->model_class}");
        }

        return [
            'total_processed' => $totalProcessed,
            'last_processed_id' => max($recordIds)
        ];
    }

    private function buildFullSyncQuery(string $modelClass, SyncState $syncState): Builder
    {
        $query = $modelClass::query();

        if ($syncState->last_processed_id) {
            $query->where('id', '>', $syncState->last_processed_id);
        }

        // Get records that haven't been synced in the last 7 days
        $query->whereNotExists(function ($query) use ($modelClass) {
            $query->from('sync_entries')
                ->where('model_class', $modelClass)
                ->where('synced_at', '>', now()->subDays(7))
                ->whereColumn('record_id', 'id');
        });

        return $query->orderBy('id');
    }

    private function buildPartialSyncQuery(string $modelClass, array $recordIds): Builder
    {
        return $modelClass::query()->whereIn('id', $recordIds);
    }

    private function ensureHeaders(SheetSyncable $model): array
    {
        // Get current headers from sheet
        $currentHeaders = $this->googleClient->getHeaders(
            $model->getSheetIdentifier(),
            $model->getSheetName()
        );

        // Get expected headers from a sample transformation
        $sampleRow = $model->toSheetRow();
        $expectedHeaders = array_keys($sampleRow);

        // If headers don't match or don't exist, set them up
        if (empty($currentHeaders) || $currentHeaders !== $expectedHeaders) {
            $this->googleClient->setHeaders(
                $model->getSheetIdentifier(),
                $model->getSheetName(),
                $expectedHeaders
            );
            return $expectedHeaders;
        }

        return $currentHeaders;
    }
} 
