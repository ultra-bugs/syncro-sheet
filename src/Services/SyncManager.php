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
use Zuko\SyncroSheet\Contracts\SheetExportable;
use Zuko\SyncroSheet\Contracts\SheetImportable;
use Zuko\SyncroSheet\Exceptions\SyncException;
use Zuko\SyncroSheet\Models\SyncState;

class SyncManager
{
    const AVAILABLE_WRITE_MODES = ['append', 'replace'];

    const DIRECTION_EXPORT = 'export';

    const DIRECTION_IMPORT = 'import';

    const DIRECTION_BIDIRECTIONAL = 'bidirectional';

    const AVAILABLE_DIRECTIONS = [
        self::DIRECTION_EXPORT,
        self::DIRECTION_IMPORT,
        self::DIRECTION_BIDIRECTIONAL,
    ];

    public function __construct(
        private readonly Exporter $exporter,
        private readonly Importer $importer,
        private readonly Reconciler $reconciler,
        private readonly StateManager $stateManager,
        private readonly SyncLogger $logger,
        private readonly NotificationManager $notificationManager,
        private readonly ErrorHandler $errorHandler
    ) {
    }

    /**
     * Start a full sync for the given model class
     */
    public function fullSync(string $modelClass, array $options = []): SyncState
    {
        $this->assertBidirectionalPrimaryKey($modelClass);

        $writeMode = $this->determineWriteMode($modelClass, $options);
        $syncState = $this->stateManager->initializeSync($modelClass, 'full', $writeMode, self::DIRECTION_EXPORT);
        $this->notificationManager->notifyStart($syncState);

        try {
            $result = $this->exporter->export($modelClass, $syncState, $writeMode);
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
        $this->assertBidirectionalPrimaryKey($modelClass);

        $this->logger->info("Starting partial sync for {$modelClass}");
        $writeMode = $this->determineWriteMode($modelClass, $options);
        $syncState = $this->stateManager->initializeSync($modelClass, 'partial', $writeMode, self::DIRECTION_EXPORT);

        try {
            $result = $this->exporter->exportPartial($modelClass, $recordIds, $syncState);
            $this->stateManager->completeSync($syncState, $result);

            $this->logger->info("Completed partial sync for {$modelClass}");

            return $syncState->fresh();
        } catch (\Exception $e) {
            $this->handleSyncError($syncState, $e);
            throw $e;
        }
    }

    /**
     * Import every sheet row back into the model table (Sheet -> DB).
     */
    public function importFull(string $modelClass, array $options = []): SyncState
    {
        $this->validateImportable($modelClass);
        $this->assertBidirectionalPrimaryKey($modelClass);

        $this->logger->info("Starting full import for {$modelClass}");
        $writeMode = $this->determineWriteMode($modelClass, $options);
        $syncState = $this->stateManager->initializeSync($modelClass, 'full', $writeMode, self::DIRECTION_IMPORT);
        $this->notificationManager->notifyStart($syncState);

        try {
            $result = $this->importer->import($modelClass, $syncState);
            $this->stateManager->completeSync($syncState, $result);
            $this->notificationManager->notifyCompletion($syncState);

            $this->logger->info("Completed full import for {$modelClass}");

            return $syncState->fresh();
        } catch (\Exception $e) {
            $this->errorHandler->handleError($syncState, $e);
            throw $e;
        }
    }

    /**
     * Import only the sheet rows whose key matches one of $recordIds.
     */
    public function importPartial(string $modelClass, array $recordIds, array $options = []): SyncState
    {
        $this->validateImportable($modelClass);
        $this->assertBidirectionalPrimaryKey($modelClass);

        $this->logger->info("Starting partial import for {$modelClass}");
        $writeMode = $this->determineWriteMode($modelClass, $options);
        $syncState = $this->stateManager->initializeSync($modelClass, 'partial', $writeMode, self::DIRECTION_IMPORT);

        try {
            $result = $this->importer->importPartial($modelClass, $recordIds, $syncState);
            $this->stateManager->completeSync($syncState, $result);

            $this->logger->info("Completed partial import for {$modelClass}");

            return $syncState->fresh();
        } catch (\Exception $e) {
            $this->handleSyncError($syncState, $e);
            throw $e;
        }
    }

    /**
     * Two-way reconcile between the model table and the sheet (Sheet <-> DB).
     */
    public function sync(string $modelClass, array $options = []): SyncState
    {
        $this->validateModel($modelClass);
        $this->validateImportable($modelClass);
        $this->assertBidirectionalPrimaryKey($modelClass);

        $this->logger->info("Starting bidirectional sync for {$modelClass}");
        $writeMode = $this->determineWriteMode($modelClass, $options);
        $syncState = $this->stateManager->initializeSync($modelClass, 'full', $writeMode, self::DIRECTION_BIDIRECTIONAL);
        $this->notificationManager->notifyStart($syncState);

        try {
            $result = $this->reconciler->reconcile($modelClass, $syncState);
            $this->stateManager->completeSync($syncState, $result);
            $this->notificationManager->notifyCompletion($syncState);

            $this->logger->info("Completed bidirectional sync for {$modelClass}");

            return $syncState->fresh();
        } catch (\Exception $e) {
            $this->errorHandler->handleError($syncState, $e);
            throw $e;
        }
    }

    private function validateModel(string $modelClass): void
    {
        if (! class_exists($modelClass)) {
            throw new SyncException("Model class {$modelClass} does not exist");
        }

        $model = new $modelClass;

        if (! $model instanceof Model || ! $model instanceof SheetExportable) {
            throw new SyncException("Model class {$modelClass} must implement SheetExportable interface");
        }
    }

    private function validateImportable(string $modelClass): void
    {
        if (! class_exists($modelClass)) {
            throw new SyncException("Model class {$modelClass} does not exist");
        }

        $model = new $modelClass;

        if (! $model instanceof Model || ! $model instanceof SheetImportable) {
            throw new SyncException("Model class {$modelClass} must implement SheetImportable interface");
        }
    }

    /**
     * Enforce the primary-key contract for bidirectional models.
     *
     * A model that is both exportable and importable must round-trip its
     * primary key through the sheet: the exported row has to carry the key
     * column at getSheetKeyColumnIndex() so the importer can match rows back
     * to records. Fail fast when a bidirectional model does not.
     */
    private function assertBidirectionalPrimaryKey(string $modelClass): void
    {
        if (! class_exists($modelClass)) {
            return; // the direction-specific validators raise the "does not exist" error
        }

        $model = new $modelClass;

        // Only bidirectional models carry this requirement; single-direction
        // models are governed by their own validation rules.
        if (! $model instanceof SheetExportable || ! $model instanceof SheetImportable) {
            return;
        }

        $keyColumn = $model->getSheetKeyColumn();

        $headers = array_values(method_exists($model, 'defaultSheetHeaders')
            ? $model->defaultSheetHeaders()
            : array_keys($model->toSheetRow()));

        $keyIndex = in_array($keyColumn, $headers, true) ? $model->getSheetKeyColumnIndex() : null;

        if ($keyIndex === null || ($headers[$keyIndex] ?? null) !== $keyColumn) {
            throw new SyncException(
                "Bidirectional sync requires the primary-key column [{$keyColumn}] at position "
                .($keyIndex ?? '?')." (getSheetKeyColumnIndex()) of the sheet row of {$modelClass}; "
                .'add it to toSheetRow()/defaultSheetHeaders() or adjust getSheetKeyColumn()/getSheetKeyColumnIndex().'
            );
        }
    }

    private function handleSyncError(SyncState $syncState, \Exception $e): void
    {
        $this->logger->error("Sync failed for {$syncState->model_class}: {$e->getMessage()}");
        $this->stateManager->failSync($syncState, $e->getMessage());
    }

    private function determineWriteMode(string $modelClass, array $options = []): string
    {
        // 1. Command line option (passed through options)
        if (! empty($options['write_mode'])) {
            return $options['write_mode'];
        }

        // 2. Runtime configuration through model instance
        $model = new $modelClass;
        if (method_exists($model, 'getPreferredWriteMode')) {
            $modelMode = $model->getPreferredWriteMode();
            if ($modelMode) {
                return $modelMode;
            }
        }

        // 3. Default from config
        return config('syncro-sheet.defaults.export.write_mode', 'append');
    }
}
