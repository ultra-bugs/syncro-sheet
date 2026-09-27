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

    const AVAILABLE_SYNC_DIRECTIONS = ['to_sheet', 'from_sheet', 'bidirectional'];

    private ?SheetReader $sheetReader = null;

    private ?RecordMatcher $recordMatcher = null;

    public function __construct(
        private readonly BatchProcessor $batchProcessor,
        private readonly StateManager $stateManager,
        private readonly SyncLogger $logger,
        private readonly NotificationManager $notificationManager,
        private readonly ErrorHandler $errorHandler
    ) {
    }

    protected function getSheetReader(): SheetReader
    {
        return $this->sheetReader ??= app(SheetReader::class);
    }

    protected function getRecordMatcher(): RecordMatcher
    {
        return $this->recordMatcher ??= app(RecordMatcher::class);
    }

    /**
     * Start a full sync for the given model class
     */
    public function fullSync(string $modelClass, array $options = []): SyncState
    {
        $syncMode = $this->determineSyncMode($modelClass, $options);
        $direction = $options['sync_direction'] ?? 'to_sheet';
        $syncState = $this->stateManager->initializeSync($modelClass, 'full', $syncMode, $direction);
        $this->notificationManager->notifyStart($syncState);

        try {
            $result = $this->batchProcessor->process($modelClass, $syncState, $syncMode);

            if ($direction === 'to_sheet' || $direction === 'bidirectional') {
                $this->writeBackIds($modelClass, $syncState);
            }

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
        $direction = $options['sync_direction'] ?? 'to_sheet';
        $syncState = $this->stateManager->initializeSync($modelClass, 'partial', $syncMode, $direction);

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

    /**
     * Sync data FROM sheet TO database
     */
    public function syncFromSheet(string $modelClass, array $options = []): SyncState
    {
        $this->validateModel($modelClass);
        $model = new $modelClass;

        if (! method_exists($model, 'fromSheetRow')) {
            throw new SyncException("{$modelClass} must implement fromSheetRow() for from_sheet sync");
        }

        $idColumn = $this->getSheetReader()->getIdColumnName($model);
        if (! $idColumn) {
            throw new SyncException("{$modelClass} must implement getIdColumnOnSheet() for from_sheet sync");
        }

        $syncState = $this->stateManager->initializeSync($modelClass, 'full', 'append', 'from_sheet');
        $this->notificationManager->notifyStart($syncState);

        try {
            $result = $this->processFromSheet($model, $modelClass, $syncState);
            $this->stateManager->completeSync($syncState, $result);
            $this->notificationManager->notifyCompletion($syncState);

            return $syncState;
        } catch (\Exception $e) {
            $this->handleSyncError($syncState, $e);
            throw $e;
        }
    }

    /**
     * Bidirectional sync: push DB→Sheet then pull Sheet→DB for new/changed rows
     */
    public function bidirectionalSync(string $modelClass, array $options = []): SyncState
    {
        $this->validateModel($modelClass);
        $model = new $modelClass;

        if (! method_exists($model, 'getIdColumnOnSheet')) {
            throw new SyncException("{$modelClass} must implement getIdColumnOnSheet() for bidirectional sync");
        }

        $toSheetState = $this->fullSync($modelClass, array_merge($options, [
            'sync_direction' => 'bidirectional',
        ]));

        if (method_exists($model, 'fromSheetRow')) {
            $this->syncFromSheet($modelClass, $options);
        }

        return $toSheetState;
    }

    private function processFromSheet(SheetSyncable $model, string $modelClass, SyncState $syncState): array
    {
        $partitioned = $this->getSheetReader()->partitionByIdColumn($model);
        $totalProcessed = 0;
        $lastProcessedId = null;

        // Process rows WITH IDs - update existing records
        if (! empty($partitioned['existing'])) {
            $dbIdMap = $this->getSheetReader()->extractDbIds($model, $partitioned['existing']);
            $matchResult = $this->getRecordMatcher()->matchByIds($modelClass, $partitioned['existing'], $dbIdMap);

            $changed = $this->getRecordMatcher()->detectChanges($model, $matchResult['matched']);

            foreach ($changed as $sheetRowNum => $changeData) {
                $changeData['db_record']->update($changeData['new_attributes']);

                $this->stateManager->recordBatchSync($syncState, [$changeData['db_id']]);
                $totalProcessed++;
                $lastProcessedId = $changeData['db_id'];
            }
        }

        // Process rows WITHOUT IDs - create new records
        if (! empty($partitioned['new'])) {
            $idColumn = $this->getSheetReader()->getIdColumnName($model);
            $newIds = [];

            foreach ($partitioned['new'] as $sheetRowNum => $row) {
                $attributes = $model->fromSheetRow($row);
                if (empty($attributes)) {
                    continue;
                }

                $record = $modelClass::create($attributes);
                $newIds[] = $record->getKey();
                $totalProcessed++;
                $lastProcessedId = $record->getKey();

                $this->stateManager->recordBatchSyncWithRowNumber(
                    $syncState,
                    [$record->getKey()],
                    $sheetRowNum
                );
            }

            // Write back new IDs to the sheet
            if (! empty($newIds) && $idColumn) {
                $this->writeIdsToSheet($model, $partitioned['new'], $newIds, $idColumn, $partitioned['headers']);
            }
        }

        return [
            'total_processed' => $totalProcessed,
            'last_processed_id' => $lastProcessedId,
        ];
    }

    /**
     * After a to_sheet sync, write DB IDs back into the id column on the sheet
     */
    private function writeBackIds(string $modelClass, SyncState $syncState): void
    {
        $model = new $modelClass;
        if (! method_exists($model, 'getIdColumnOnSheet')) {
            return;
        }

        $idColumn = $model->getIdColumnOnSheet();
        if (! $idColumn) {
            return;
        }

        $this->logger->info("Writing back IDs to sheet column [{$idColumn}] for {$modelClass}");

        $data = $this->getSheetReader()->readSheetData($model);
        if (empty($data['rows'])) {
            return;
        }

        $idColumnIndex = array_search($idColumn, $data['headers']);
        if ($idColumnIndex === false) {
            return;
        }

        $entries = $syncState->entries()
            ->where('status', 'success')
            ->get()
            ->keyBy('record_id');

        $sheetRows = $this->getSheetReader()->readSheetData($model);
        $updates = [];

        foreach ($sheetRows['rows'] as $rowNum => $row) {
            $existingId = $row[$idColumn] ?? null;
            if ($existingId !== null && $existingId !== '') {
                continue;
            }

            foreach ($entries as $recordId => $entry) {
                $dbRecord = $modelClass::find($recordId);
                if (! $dbRecord) {
                    continue;
                }

                $dbRow = $dbRecord->toSheetRow();
                if ($this->rowMatchesSheetData($dbRow, $row, $data['headers'], $idColumn)) {
                    $updates[$rowNum] = [$idColumnIndex => $recordId];
                    $entries->forget($recordId);
                    break;
                }
            }
        }

        if (! empty($updates)) {
            $googleClient = app(GoogleClient::class);
            foreach ($updates as $rowNum => $cellUpdates) {
                $range = $this->columnLetterFromIndex($idColumnIndex) . $rowNum;
                $googleClient->updateCell(
                    $model->getSheetIdentifier(),
                    $model->getSheetName(),
                    $range,
                    array_values($cellUpdates)[0]
                );
            }

            $this->logger->info(sprintf('Wrote back %d IDs to sheet', count($updates)));
        }
    }

    private function writeIdsToSheet(SheetSyncable $model, array $sheetRows, array $newIds, string $idColumn, array $headers): void
    {
        $idColumnIndex = array_search($idColumn, $headers);
        if ($idColumnIndex === false) {
            return;
        }

        $googleClient = app(GoogleClient::class);
        $rowNums = array_keys($sheetRows);
        $idIndex = 0;

        foreach ($rowNums as $rowNum) {
            if (! isset($newIds[$idIndex])) {
                break;
            }

            $range = $this->columnLetterFromIndex($idColumnIndex) . $rowNum;
            $googleClient->updateCell(
                $model->getSheetIdentifier(),
                $model->getSheetName(),
                $range,
                $newIds[$idIndex]
            );
            $idIndex++;
        }
    }

    private function rowMatchesSheetData(array $dbRow, array $sheetRow, array $headers, string $idColumn): bool
    {
        $matchCount = 0;
        $totalComparisons = 0;

        foreach ($headers as $index => $header) {
            if ($header === $idColumn) {
                continue;
            }

            $dbValue = $dbRow[$index] ?? $dbRow[$header] ?? null;
            $sheetValue = $sheetRow[$header] ?? null;

            if ($dbValue === null && $sheetValue === null) {
                continue;
            }

            $totalComparisons++;
            if ((string) $dbValue === (string) $sheetValue) {
                $matchCount++;
            }
        }

        return $totalComparisons > 0 && ($matchCount / $totalComparisons) >= 0.7;
    }

    private function columnLetterFromIndex(int $index): string
    {
        $letter = '';
        $num = $index;
        while ($num >= 0) {
            $letter = chr(($num % 26) + 65) . $letter;
            $num = intdiv($num, 26) - 1;
        }

        return $letter;
    }

    private function validateModel(string $modelClass): void
    {
        if (! class_exists($modelClass)) {
            throw new SyncException("Model class {$modelClass} does not exist");
        }

        $model = new $modelClass;

        if (! $model instanceof Model || ! $model instanceof SheetSyncable) {
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
        if (! empty($options['sync_mode'])) {
            return $options['sync_mode'];
        }

        $model = new $modelClass;
        if (method_exists($model, 'getPreferredSyncMode')) {
            $modelMode = $model->getPreferredSyncMode();
            if ($modelMode) {
                return $modelMode;
            }
        }

        return config('syncro-sheet.defaults.sync_mode', 'append');
    }
}
