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
     * Normalize a raw associative sheet row (header => string) coming back from
     * Google Sheets before it is handed to the model's fromSheetRow().
     *
     * Google Sheets always yields strings; here we only do format-level clean-up
     * (trim, empty string -> null). Type coercion is left to the model's
     * fromSheetRow() and Eloquent casts, which know the target attribute types.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public function parseRow(array $row): array
    {
        return array_map(fn ($value) => $this->parseValue($value), $row);
    }

    /**
     * Normalize a single value read from a sheet cell.
     */
    private function parseValue($value)
    {
        if (is_string($value)) {
            $value = trim($value);

            return $value === '' ? null : $value;
        }

        return $value;
    }

    /**
     * Produce an ordered list of formatted cell values for the given headers,
     * ready to be written to a sheet row.
     *
     * @param  array<string, mixed>  $assoc    header => value
     * @param  array<int, string>    $headers  the column order to emit
     * @return array<int, string>
     */
    public function formatRow(array $assoc, array $headers): array
    {
        $row = [];
        foreach ($headers as $header) {
            $row[] = $this->formatValue($assoc[$header] ?? null);
        }

        return $row;
    }

    /**
     * Build a canonical content hash for one record/row, used by the reconciler
     * to compare the DB side and the sheet side on equal footing.
     *
     * Both sides run through formatValue() over the same ordered header subset
     * (minus $ignore), so DB null == sheet '' and DB 10 == sheet '10'.
     *
     * @param  array<string, mixed>  $assoc    header => value (DB toSheetRow() or a sheet row)
     * @param  array<int, string>    $headers  the column set to hash, in order
     * @param  array<int, string>    $ignore   columns to exclude (e.g. volatile timestamps)
     */
    public function canonicalHash(array $assoc, array $headers, array $ignore = []): string
    {
        $canonical = [];
        foreach ($headers as $header) {
            if (in_array($header, $ignore, true)) {
                continue;
            }
            $canonical[$header] = $this->formatValue($assoc[$header] ?? null);
        }

        return hash('xxh3', json_encode($canonical));
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
