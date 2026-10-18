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

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Zuko\SyncroSheet\Models\SyncState;

class StateManager
{
    /** Slack past defaults.timeout before a `running` run counts as abandoned. */
    public const ABANDON_GRACE_SECONDS = 60;

    public function __construct(
        private readonly SyncLogger $logger
    ) {
    }

    /**
     * Connection holding the tracking tables.
     */
    private function tracking(): Connection
    {
        return DB::connection(TrackingDatabase::connectionName());
    }

    /**
     * Initialize a new sync state
     */
    public function initializeSync(
        string $modelClass,
        string $syncType,
        string $writeMode,
        string $direction = SyncManager::DIRECTION_EXPORT
    ): SyncState {
        $this->failAbandonedRuns($modelClass);

        return SyncState::create([
            'model_class' => $modelClass,
            'sync_type' => $syncType,
            'direction' => $direction,
            'write_mode' => $writeMode,
            'status' => 'running',
            'started_at' => now(),
        ]);
    }

    /**
     * End the model's runs still `running` past the run timeout: their worker
     * died (timeout kill, out of memory, deploy) before reporting a result.
     */
    public function failAbandonedRuns(string $modelClass): int
    {
        $after = (int) config('syncro-sheet.defaults.timeout', 600) + self::ABANDON_GRACE_SECONDS;

        $abandoned = SyncState::query()
            ->where('model_class', $modelClass)
            ->where('status', 'running')
            ->where('started_at', '<', now()->subSeconds($after))
            ->get();

        foreach ($abandoned as $syncState) {
            $this->failSync($syncState, "Abandoned: no result after {$after} s (worker stopped?)");
        }

        return $abandoned->count();
    }

    /**
     * Record successful sync for a batch of records
     */
    public function recordBatchSync(SyncState $syncState, array $recordIds): void
    {
        $entries = array_map(function ($recordId) use ($syncState) {
            return [
                'sync_state_id' => $syncState->id,
                'model_class' => $syncState->model_class,
                'record_id' => $recordId,
                'synced_at' => now(),
                'sync_type' => $syncState->sync_type,
                'status' => 'success',
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }, $recordIds);

        $this->tracking()->table('sync_entries')->insert($entries);

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
     * Get the last successful sync state for a model
     */
    public function getLastSuccessfulSync(string $modelClass): ?SyncState
    {
        return SyncState::where('model_class', $modelClass)
            ->where('status', 'completed')
            ->latest('completed_at')
            ->first();
    }

    /**
     * Load the reconcile baseline for a model: record_id => the content hash
     * of each side when they were last in sync (sync_snapshots; a row written
     * before sheet_hash existed gives both sides content_hash).
     *
     * @return array<string, array{db: string, sheet: string}>
     */
    public function baselineFor(string $modelClass): array
    {
        return $this->tracking()->table('sync_snapshots')
            ->where('model_class', $modelClass)
            ->get(['record_id', 'content_hash', 'sheet_hash'])
            ->mapWithKeys(fn ($row) => [(string) $row->record_id => [
                'db' => $row->content_hash,
                'sheet' => $row->sheet_hash ?? $row->content_hash,
            ]])
            ->all();
    }

    /**
     * Upsert the reconcile baseline for a batch of records: the content hash
     * each side had when the two were last in sync. A single hash means both
     * sides had that form.
     *
     * @param  array<int|string, string|array{db: string, sheet: string}>  $hashesByRecordId
     */
    public function storeSnapshots(string $modelClass, array $hashesByRecordId): void
    {
        if (empty($hashesByRecordId)) {
            return;
        }

        $now = now();

        $rows = [];
        foreach ($hashesByRecordId as $recordId => $hash) {
            $rows[] = [
                'model_class' => $modelClass,
                'record_id' => $recordId,
                'content_hash' => is_array($hash) ? $hash['db'] : $hash,
                'sheet_hash' => is_array($hash) ? $hash['sheet'] : $hash,
                'synced_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $this->tracking()->table('sync_snapshots')->upsert(
            $rows,
            ['model_class', 'record_id'],
            ['content_hash', 'sheet_hash', 'synced_at', 'updated_at']
        );
    }
}
