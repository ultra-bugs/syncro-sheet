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

class ErrorHandler
{
    public function __construct(
        private readonly NotificationManager $notificationManager,
        private readonly SyncLogger $logger,
        private readonly int $maxRetries = 3
    ) {
    }

    /**
     * Handle sync error
     */
    public function handleError(
        SyncState $syncState,
        \Exception $exception,
        array $failedRecords = []
    ): void {
        $currentRetries = $this->getCurrentRetries($syncState);

        if ($currentRetries < $this->maxRetries) {
            $this->handleRetry($syncState, $failedRecords, $currentRetries + 1);
        } else {
            $this->handleFinalFailure($syncState, $exception);
        }
    }

    private function handleRetry(SyncState $syncState, array $failedRecords, int $retryCount): void
    {
        $this->logger->warning(
            "Retry attempt {$retryCount} for sync {$syncState->id}",
            ['failed_records' => $failedRecords]
        );

        // Schedule a new partial sync for failed records
        $this->schedulePartialSync($syncState->model_class, $failedRecords, $retryCount);

        $this->notificationManager->notifyRetry($syncState, $failedRecords, $retryCount);
    }

    private function handleFinalFailure(SyncState $syncState, \Exception $exception): void
    {
        $this->logger->error(
            "Sync failed after maximum retries for {$syncState->model_class}",
            ['error' => $exception->getMessage()]
        );

        $this->notificationManager->notifyFinalFailure($syncState, $exception);
    }

    private function getCurrentRetries(SyncState $syncState): int
    {
        return DB::table('sync_states')
            ->where('model_class', $syncState->model_class)
            ->where('status', 'failed')
            ->where('created_at', '>', now()->subHours(1))
            ->count();
    }

    private function schedulePartialSync(string $modelClass, array $recordIds, int $retryCount): void
    {
        // Use Laravel's scheduler to retry after exponential backoff
        $delay = pow(2, $retryCount - 1) * 60; // 1min, 2min, 4min...

        \Illuminate\Support\Facades\Queue::later(
            now()->addSeconds($delay),
            new \Zuko\SyncroSheet\Jobs\PartialSyncJob($modelClass, $recordIds)
        );
    }
}
