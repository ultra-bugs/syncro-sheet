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

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Zuko\SyncroSheet\Contracts\SheetSyncable;

class DataTransformer
{
    /**
     * Transform a batch of records into sheet rows
     */
    public function transformBatch(Collection $records): array
    {
        if ($records->isEmpty()) {
            return [];
        }

        return $records->map(function (SheetSyncable $record) {
            return $this->transformRecord($record);
        })->toArray();
    }

    /**
     * Transform a single record into a sheet row
     */
    private function transformRecord(SheetSyncable $record): array
    {
        $row = $record->toSheetRow();

        return array_map(function ($value) {
            return $this->formatValue($value);
        }, $row);
    }

    /**
     * Format a value for Google Sheets
     */
    private function formatValue($value): string
    {
        if ($value instanceof Carbon) {
            return $value->format('Y-m-d H:i:s');
        }

        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        if (is_array($value) || is_object($value)) {
            return json_encode($value);
        }

        if (is_null($value)) {
            return '';
        }

        return (string) $value;
    }
}
