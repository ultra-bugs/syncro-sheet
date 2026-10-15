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

use Zuko\SyncroSheet\Contracts\SheetImportable;
use Zuko\SyncroSheet\Exceptions\ImportException;
use Zuko\SyncroSheet\Models\SyncState;

/**
 * Import engine: pulls rows FROM a Google Sheet back into Eloquent records
 * (Sheet -> DB). Counterpart of {@see Exporter}.
 *
 * Rows are matched to records through the primary-key column declared by
 * {@see SheetImportable::getSheetKeyColumn()} and persisted with upsert
 * semantics (update when matched, create when new; never deletes).
 */
class Importer
{
    public function __construct(
        private readonly StateManager $stateManager,
        private readonly DataTransformer $transformer,
        private readonly GoogleClient $googleClient,
        private readonly SyncLogger $logger
    ) {
    }

    /**
     * Import every row of the sheet into the model table.
     */
    public function import(string $modelClass, SyncState $syncState): array
    {
        return $this->readAndPersist($modelClass, $syncState, null);
    }

    /**
     * Import only the rows whose key matches one of $recordIds.
     */
    public function importPartial(string $modelClass, array $recordIds, SyncState $syncState): array
    {
        return $this->readAndPersist($modelClass, $syncState, $recordIds);
    }

    /**
     * Read the whole sheet, then upsert the (optionally filtered) rows in
     * memory-efficient chunks.
     *
     * @param  array<int, mixed>|null  $allowedIds  null = import all rows
     */
    private function readAndPersist(string $modelClass, SyncState $syncState, ?array $allowedIds): array
    {
        /** @var SheetImportable $model */
        $model = new $modelClass;

        $batchSize = method_exists($model, 'getBatchSize')
            ? $model->getBatchSize()
            : config('syncro-sheet.defaults.batch_size');

        $values = $this->googleClient->readSheet(
            $model->getSheetIdentifier(),
            $model->getSheetName()
        );

        // First row holds the headers; nothing to import without them.
        $headers = array_shift($values);
        if (empty($headers) || empty($values)) {
            $this->logger->info("No rows to import for {$modelClass}");

            return ['total_processed' => 0, 'last_processed_id' => null];
        }

        $keyColumn = $model->getSheetKeyColumn();
        if (! in_array($keyColumn, $headers, true)) {
            throw new ImportException(
                "Sheet is missing the key column [{$keyColumn}] required to import {$modelClass}"
            );
        }

        $allowed = $allowedIds === null
            ? null
            : array_map('strval', $allowedIds);

        $useBatchUpsert = (bool) config('syncro-sheet.defaults.import.use_batch_upsert', false);
        $modelKey = $model->getKeyName();

        $totalProcessed = 0;
        $lastProcessedId = null;

        foreach (array_chunk($values, $batchSize) as $chunk) {
            $attributeRows = $this->prepareAttributeRows(
                $model, $headers, $chunk, $keyColumn, $modelKey, $allowed
            );

            if (empty($attributeRows)) {
                continue;
            }

            $processedIds = $useBatchUpsert
                ? $this->batchUpsert($modelClass, $attributeRows, $modelKey)
                : $this->rowByRowUpsert($modelClass, $attributeRows, $modelKey);

            $this->stateManager->recordBatchSync($syncState, $processedIds);

            $processedCount = count($processedIds);
            $totalProcessed += $processedCount;
            $lastProcessedId = max($processedIds);

            $this->logger->info("Imported chunk of {$processedCount} records for {$modelClass}");
        }

        return [
            'total_processed' => $totalProcessed,
            'last_processed_id' => $lastProcessedId,
        ];
    }

    /**
     * Turn raw sheet rows into [key => value, attributes => [...]] pairs,
     * applying the partial filter and the model's fromSheetRow() mapping.
     *
     * @return array<int, array{key: mixed, attributes: array<string, mixed>}>
     */
    private function prepareAttributeRows(
        SheetImportable $model,
        array $headers,
        array $chunk,
        string $keyColumn,
        string $modelKey,
        ?array $allowed
    ): array {
        $prepared = [];

        foreach ($chunk as $row) {
            $assoc = $this->transformer->parseRow($this->rowToAssoc($headers, $row));

            $keyValue = $assoc[$keyColumn] ?? null;
            if ($keyValue === null) {
                // A row without a primary key cannot be matched; skip it.
                continue;
            }

            if ($allowed !== null && ! in_array((string) $keyValue, $allowed, true)) {
                continue;
            }

            $attributes = $model->fromSheetRow($assoc);
            // Keep the matched key out of the "updatable" attributes to avoid
            // rewriting the primary key through a mutator by accident.
            unset($attributes[$keyColumn]);
            $attributes[$modelKey] = $keyValue;

            $prepared[] = ['key' => $keyValue, 'attributes' => $attributes];
        }

        return $prepared;
    }

    /**
     * Per-row upsert via updateOrCreate: fires model events and respects casts.
     *
     * @return array<int, mixed>  persisted primary keys
     */
    private function rowByRowUpsert(string $modelClass, array $attributeRows, string $modelKey): array
    {
        $ids = [];

        foreach ($attributeRows as $prepared) {
            $attributes = $prepared['attributes'];
            unset($attributes[$modelKey]);

            $record = $modelClass::updateOrCreate(
                [$modelKey => $prepared['key']],
                $attributes
            );

            $ids[] = $record->getKey();
        }

        return $ids;
    }

    /**
     * Batch upsert via Model::upsert(): one query per chunk, but skips model
     * events and mutators. Opt-in through config for large, trusted imports.
     *
     * @return array<int, mixed>  persisted primary keys
     */
    private function batchUpsert(string $modelClass, array $attributeRows, string $modelKey): array
    {
        $values = array_map(static fn ($prepared) => $prepared['attributes'], $attributeRows);

        $modelClass::upsert($values, [$modelKey]);

        return array_map(static fn ($prepared) => $prepared['key'], $attributeRows);
    }

    /**
     * Combine a header list with a (possibly ragged) data row into an
     * associative array, padding/truncating to the header count.
     *
     * @return array<string, mixed>
     */
    private function rowToAssoc(array $headers, array $row): array
    {
        $size = count($headers);
        $row = array_pad(array_slice($row, 0, $size), $size, null);

        return array_combine($headers, $row);
    }
}
