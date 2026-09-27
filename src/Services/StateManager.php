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

use Illuminate\Support\Facades\DB;
use Zuko\SyncroSheet\Models\SyncState;

class StateManager
{
    public function __construct(
        private readonly SyncLogger $logger
    ) {}

    /**
     * Initialize a new sync state
     */
    public function initializeSync(string $modelClass, string $syncType, string $syncMode, string $syncDirection = 'to_sheet'): SyncState
    {
        return SyncState::create([
            'model_class' => $modelClass,
            'sync_type' => $syncType,
            'sync_mode' => $syncMode,
            'sync_direction' => $syncDirection,
            'status' => 'running',
            'started_at' => now(),
        ]);
    }

    /**
     * Record successful sync for a batch of records
     *
     * @param  array<string, string>  $contentHashes  Optional map of recordId => contentHash
     */
    public function recordBatchSync(SyncState $syncState, array $recordIds, array $contentHashes = []): void
    {
        $entries = array_map(function ($recordId) use ($syncState, $contentHashes) {
            return [
                'sync_state_id' => $syncState->id,
                'model_class' => $syncState->model_class,
                'record_id' => $recordId,
                'content_hash' => $contentHashes[$recordId] ?? null,
                'synced_at' => now(),
                'sync_type' => $syncState->sync_type,
                'status' => 'success',
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }, $recordIds);

        DB::table('sync_entries')->insert($entries);

        $syncState->update([
            'total_processed' => $syncState->total_processed + count($recordIds),
            'last_processed_id' => max($recordIds),
        ]);
    }

    /**
     * Complete a sync operation
     */
    public function completeSync(SyncState $syncState, array $result): void
    {
        $syncState->update([
            'status' => 'completed',
            'completed_at' => now(),
            'total_processed' => $result['total_processed'],
            'last_processed_id' => $result['last_processed_id'],
        ]);

        $this->logger->info("Sync completed for {$syncState->model_class}", $result);
    }

    /**
     * Mark a sync operation as failed
     */
    public function failSync(SyncState $syncState, string $error): void
    {
        $syncState->update([
            'status' => 'failed',
            'completed_at' => now(),
            'error_message' => $error,
        ]);

        $this->logger->error("Sync failed for {$syncState->model_class}: {$error}");
    }

    /**
     * Record batch sync with sheet row number tracking
     */
    public function recordBatchSyncWithRowNumber(SyncState $syncState, array $recordIds, int $sheetRowNumber, ?string $contentHash = null): void
    {
        $entries = array_map(function ($recordId) use ($syncState, $sheetRowNumber, $contentHash) {
            return [
                'sync_state_id' => $syncState->id,
                'model_class' => $syncState->model_class,
                'record_id' => $recordId,
                'sheet_row_number' => $sheetRowNumber,
                'content_hash' => $contentHash,
                'synced_at' => now(),
                'sync_type' => $syncState->sync_type,
                'status' => 'success',
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }, $recordIds);

        DB::table('sync_entries')->insert($entries);

        $syncState->update([
            'total_processed' => $syncState->total_processed + count($recordIds),
            'last_processed_id' => max($recordIds),
        ]);
    }

    /**
     * Get the last successful sync state for a model
     */
    public function getLastSuccessfulSync(string $modelClass): ?SyncState
    {
        return SyncState::where('model_class', $modelClass)
            ->where('status', 'completed')
            ->latest('completed_at')
            ->first();
    }
}
