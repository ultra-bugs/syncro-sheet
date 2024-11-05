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
use Illuminate\Support\Collection;
use Zuko\SyncroSheet\Exceptions\SyncException;

class SheetRowMapper
{
    private const HASH_COLUMN = '_record_hash';

    private const ROW_INDEX_COLUMN = '_row_index';

    private FuzzyRecordIdentifier $fuzzyIdentifier;

    public function __construct(?FuzzyRecordIdentifier $fuzzyIdentifier = null)
    {
        $this->fuzzyIdentifier = $fuzzyIdentifier ?? new FuzzyRecordIdentifier();
    }

    /**
     * Generate a unique hash for a model instance
     */
    public function generateRecordHash(Model $model): string
    {
        // If model has a primary key, use it as part of the hash
        $identifier = [];

        if ($model->getKey()) {
            $identifier[] = $model->getKey();
        }

        // Get unique identifying attributes (configured in model)
        if (method_exists($model, 'getSheetUniqueAttributes')) {
            $uniqueAttributes = $model->getSheetUniqueAttributes();
            foreach ($uniqueAttributes as $attribute) {
                $identifier[] = $model->getAttribute($attribute);
            }
        }

        // If no identifying attributes, use fuzzy identification
        if (empty($identifier)) {
            $identifyingFields = $this->fuzzyIdentifier->analyzeModel($model);
            foreach ($identifyingFields as $field) {
                $identifier[] = $model->getAttribute($field);
            }
        }

        // Generate hash using all identifying data
        return hash('xxh3', serialize($identifier));
    }

    /**
     * Map model instances to sheet rows
     */
    public function mapRecordsToSheet(Collection $records, array $sheetData): array
    {
        $headerRow = array_shift($sheetData);
        if (! $headerRow) {
            return $this->prepareNewSheetData($records);
        }

        // Find hash column index
        $hashColumnIndex = array_search(self::HASH_COLUMN, $headerRow);
        if ($hashColumnIndex === false) {
            // Add hash column if it doesn't exist
            $headerRow[] = self::HASH_COLUMN;
            $hashColumnIndex = count($headerRow) - 1;

            // Add hash column to existing rows
            foreach ($sheetData as &$row) {
                $row[$hashColumnIndex] = '';
            }
        }

        // Build hash index for existing records
        $existingHashes = [];
        foreach ($sheetData as $rowIndex => $row) {
            if (isset($row[$hashColumnIndex]) && $row[$hashColumnIndex] !== '') {
                $existingHashes[$row[$hashColumnIndex]] = $rowIndex + 1; // +1 for header row
            }
        }

        // Process records and prepare updates
        $updates = [];
        $newRows = [];

        foreach ($records as $record) {
            $hash = $this->generateRecordHash($record);
            $rowData = $this->prepareRowData($record, $headerRow);
            $rowData[$hashColumnIndex] = $hash;

            if (isset($existingHashes[$hash])) {
                // Update existing row
                $updates[$existingHashes[$hash]] = $rowData;
            } else {
                // Add new row
                $newRows[] = $rowData;
            }
        }

        return [
            'header' => $headerRow,
            'updates' => $updates,
            'new_rows' => $newRows,
        ];
    }

    /**
     * Prepare data for a new sheet
     */
    private function prepareNewSheetData(Collection $records): array
    {
        if ($records->isEmpty()) {
            return [
                'header' => [],
                'updates' => [],
                'new_rows' => [],
            ];
        }

        // Get header from first record
        $firstRecord = $records->first();
        $header = $this->getHeadersFromModel($firstRecord);
        $header[] = self::HASH_COLUMN;

        // Prepare rows
        $rows = [];
        foreach ($records as $record) {
            $rowData = $this->prepareRowData($record, $header);
            $rowData[] = $this->generateRecordHash($record);
            $rows[] = $rowData;
        }

        return [
            'header' => $header,
            'updates' => [],
            'new_rows' => $rows,
        ];
    }

    /**
     * Get headers from model
     */
    private function getHeadersFromModel(Model $model): array
    {
        if (method_exists($model, 'defaultSheetHeaders')) {
            return $model->defaultSheetHeaders();
        }

        // Fallback to getting headers from toSheetRow
        if (method_exists($model, 'toSheetRow')) {
            return array_keys($model->toSheetRow());
        }

        throw new SyncException('Model must implement either defaultSheetHeaders() or toSheetRow()');
    }

    /**
     * Prepare row data ensuring it matches headers
     */
    private function prepareRowData(Model $model, array $headers): array
    {
        $rowData = method_exists($model, 'toSheetRow')
            ? $model->toSheetRow()
            : $model->toArray();

        // Ensure data matches headers (excluding hash column)
        $data = [];
        foreach ($headers as $header) {
            if ($header === self::HASH_COLUMN) {
                continue;
            }
            $data[] = $rowData[$header] ?? null;
        }

        return $data;
    }
}
