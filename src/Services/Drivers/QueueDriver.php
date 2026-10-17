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

namespace Zuko\SyncroSheet\Services\Drivers;

use Zuko\SyncroSheet\Contracts\SyncDriver;
use Zuko\SyncroSheet\Jobs\ImportBatchJob;
use Zuko\SyncroSheet\Jobs\PartialSyncJob;
use Zuko\SyncroSheet\Jobs\ReconcileApplyJob;
use Zuko\SyncroSheet\Services\GoogleClient;
use Zuko\SyncroSheet\Services\Reconciler;
use Zuko\SyncroSheet\Services\StateManager;
use Zuko\SyncroSheet\Services\SyncManager;

/**
 * Fans the work out to queued jobs. The scheduler stays the dispatcher: it reads
 * the source side and dispatches apply jobs.
 *
 *   - export       -> PartialSyncJob per chunk of record IDs
 *   - import       -> ImportBatchJob per chunk of sheet rows
 *   - bidirectional-> Reconciler::decide() centrally, then ReconcileApplyJob per
 *                     chunk of decisions (conflict resolution already done).
 */
class QueueDriver implements SyncDriver
{
    public function __construct(
        private readonly GoogleClient $googleClient,
        private readonly Reconciler $reconciler,
        private readonly StateManager $stateManager
    ) {
    }

    public function dispatch(string $modelClass, string $direction, array $options = []): void
    {
        match ($direction) {
            SyncManager::DIRECTION_IMPORT => $this->dispatchImport($modelClass),
            SyncManager::DIRECTION_BIDIRECTIONAL => $this->dispatchReconcile($modelClass),
            default => $this->dispatchExport($modelClass),
        };
    }

    private function dispatchExport(string $modelClass): void
    {
        $keyName = (new $modelClass)->getKeyName();

        $modelClass::query()
            ->pluck($keyName)
            ->chunk($this->chunkSize())
            ->each(fn ($chunk) => $this->queue(new PartialSyncJob($modelClass, $chunk->values()->all())));
    }

    private function dispatchImport(string $modelClass): void
    {
        $model = new $modelClass;

        $values = $this->googleClient->readSheet($model->getSheetIdentifier(), $model->getSheetName());
        $headers = array_shift($values);

        if (empty($headers) || empty($values)) {
            return;
        }

        foreach (array_chunk($values, $this->chunkSize()) as $rows) {
            $this->queue(new ImportBatchJob($modelClass, $headers, $rows));
        }
    }

    private function dispatchReconcile(string $modelClass): void
    {
        $plan = $this->reconciler->decide($modelClass);

        // Records that already agree get their baseline seeded here (no apply
        // job is dispatched for them).
        $this->stateManager->storeSnapshots($modelClass, $plan['seeds']);

        foreach (array_chunk($plan['decisions'], $this->chunkSize()) as $decisions) {
            $this->queue(new ReconcileApplyJob($modelClass, $decisions));
        }
    }

    private function queue(object $job): void
    {
        $pending = dispatch($job);

        if (($connection = config('syncro-sheet.scheduler.queue.connection')) !== null) {
            $pending->onConnection($connection);
        }

        if (($queue = config('syncro-sheet.scheduler.queue.queue')) !== null) {
            $pending->onQueue($queue);
        }
    }

    private function chunkSize(): int
    {
        return (int) config('syncro-sheet.scheduler.queue.chunk', 500);
    }
}
