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
use Zuko\SyncroSheet\Contracts\SheetSyncable;
use Zuko\SyncroSheet\Exceptions\SyncException;
use Zuko\SyncroSheet\Models\SyncState;

class SyncManager
{
    const AVAILABLE_SYNC_MODES = ['append', 'replace'];
    public function __construct(
        private readonly BatchProcessor $batchProcessor,
        private readonly StateManager $stateManager,
        private readonly SyncLogger $logger,
        private readonly NotificationManager $notificationManager,
        private readonly ErrorHandler $errorHandler
    ) {}

    /**
     * Start a full sync for the given model class
     */
    public function fullSync(string $modelClass, array $options = []): SyncState
    {
        $syncMode = $this->determineSyncMode($modelClass, $options);
        $syncState = $this->stateManager->initializeSync($modelClass, 'full', $syncMode);
        $this->notificationManager->notifyStart($syncState);

        try {
            $result = $this->batchProcessor->process($modelClass, $syncState, $syncMode);
            $this->stateManager->completeSync($syncState, $result);
            $this->notificationManager->notifyCompletion($syncState);
            return $syncState;
        } catch (\Exception $e) {
            $this->errorHandler->handleError($syncState, $e);
            throw $e;
        }
    }

    /**
     * Start a partial sync for specific model instances
     */
    public function partialSync(string $modelClass, array $recordIds, array $options = []): SyncState
    {
        $this->validateModel($modelClass);

        $this->logger->info("Starting partial sync for {$modelClass}");
        $syncMode = $this->determineSyncMode($modelClass, $options);
        $syncState = $this->stateManager->initializeSync($modelClass, 'partial', $syncMode);

        try {
            $result = $this->batchProcessor->processPartial($modelClass, $recordIds, $syncState);
            $this->stateManager->completeSync($syncState, $result);
            
            $this->logger->info("Completed partial sync for {$modelClass}");
            
            return $syncState->fresh();
        } catch (\Exception $e) {
            $this->handleSyncError($syncState, $e);
            throw $e;
        }
    }

    private function validateModel(string $modelClass): void
    {
        if (!class_exists($modelClass)) {
            throw new SyncException("Model class {$modelClass} does not exist");
        }

        $model = new $modelClass;
        
        if (!$model instanceof Model || !$model instanceof SheetSyncable) {
            throw new SyncException("Model class {$modelClass} must implement SheetSyncable interface");
        }
    }

    private function handleSyncError(SyncState $syncState, \Exception $e): void
    {
        $this->logger->error("Sync failed for {$syncState->model_class}: {$e->getMessage()}");
        $this->stateManager->failSync($syncState, $e->getMessage());
    }

    private function determineSyncMode(string $modelClass, array $options = []): string
    {
        // 1. Command line option (passed through options)
        if (!empty($options['sync_mode'])) {
            return $options['sync_mode'];
        }

        // 2. Runtime configuration through model instance
        $model = new $modelClass;
        if (method_exists($model, 'getPreferredSyncMode')) {
            $modelMode = $model->getPreferredSyncMode();
            if ($modelMode) {
                return $modelMode;
            }
        }
        // 3. Default from config
        return config('syncro-sheet.defaults.sync_mode', 'append');
    }
} 
