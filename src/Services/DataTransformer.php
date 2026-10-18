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
use Zuko\SyncroSheet\Contracts\SheetExportable;

/**
 * Value codec — the single place that converts values between a model and a
 * sheet cell, in both directions:
 *
 * - export (model -> cell): recordToAssoc() / transformRecord(), formatRow(), formatValue()
 * - import (cell -> model): parseRow() / parseValue()
 * - reconcile: canonicalHash() builds a normalized content hash so the DB side
 *   and the sheet side can be compared on equal footing.
 *
 * Subclass it per model to change how that model's data is written or read
 * (override transformRecord(), formatValue(), parseValue()) and return it from
 * the model's sheetTransformer() hook (see InteractsWithSheet). The engines
 * use the model's transformer for every write, read and hash, so both sides
 * of the content hash are always formatted the same way.
 *
 * It holds no state and performs no I/O; the engines (Exporter, Importer,
 * Reconciler) call it.
 */
class DataTransformer
{
    /**
     * The transformer the given model asks for (getSheetTransformer(), see
     * InteractsWithSheet), or this one.
     */
    public function forModel(object $model): DataTransformer
    {
        return method_exists($model, 'getSheetTransformer') ? $model->getSheetTransformer() : $this;
    }

    /**
     * Transform a batch of records into sheet rows
     */
    public function transformBatch(Collection $records): array
    {
        if ($records->isEmpty()) {
            return [];
        }

        return $records->map(function (SheetExportable $record) {
            return array_map(fn ($value) => $this->formatValue($value), $this->transformRecord($record));
        })->toArray();
    }

    /**
     * One record as header => value, the way it is written to the sheet (and
     * hashed on the DB side).
     *
     * @param  array<int, string>  $headers  the model's sheet headers (for positional rows)
     * @return array<string, mixed>
     */
    public function recordToAssoc(SheetExportable $record, array $headers): array
    {
        return $this->toAssoc($this->transformRecord($record), $headers);
    }

    /**
     * Record -> sheet row (positional list or header => value). Override to
     * change what is written for a model; values are formatted afterwards by
     * formatValue().
     */
    protected function transformRecord(SheetExportable $record): array
    {
        return $record->toSheetRow();
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
     * Normalize a single value read from a sheet cell. Override to change how
     * a model's cells are read.
     */
    protected function parseValue($value)
    {
        if (is_string($value)) {
            $value = trim($value);

            return $value === '' ? null : $value;
        }

        return $value;
    }

    /**
     * Normalise a row coming from toSheetRow() into header => value.
     *
     * A list (positional) row is combined with $headers by position, padded or
     * truncated to the header count; blank headers only reserve a position and
     * are dropped. An associative row is returned unchanged.
     *
     * @param  array<int|string, mixed>  $row
     * @param  array<int, string>        $headers
     * @return array<string, mixed>
     */
    public function toAssoc(array $row, array $headers): array
    {
        if (! array_is_list($row)) {
            return $row;
        }

        $assoc = [];
        foreach (array_values($headers) as $position => $header) {
            if ($header !== '' && $header !== null) {
                $assoc[$header] = $row[$position] ?? null;
            }
        }

        return $assoc;
    }

    /**
     * Produce an ordered list of formatted cell values for the given headers,
     * ready to be written to a sheet row.
     *
     * @param  array<int|string, mixed>  $row      header => value, or a positional list
     * @param  array<int, string>        $headers  the column order to emit
     * @return array<int, string|int|float>
     */
    public function formatRow(array $row, array $headers): array
    {
        $assoc = $this->toAssoc($row, $headers);

        $values = [];
        foreach ($headers as $header) {
            $values[] = $this->formatValue($header === '' ? null : ($assoc[$header] ?? null));
        }

        return $values;
    }

    /**
     * Build a canonical content hash for one record/row, used by the reconciler
     * to compare the DB side and the sheet side on equal footing.
     *
     * Both sides run through the same normalisation over the same ordered
     * header subset (minus $ignore and blank headers): DB null == sheet '',
     * and numerics compare by value (DB "10.00" == sheet 10 == "10").
     *
     * @param  array<int|string, mixed>  $row      header => value (or a positional list)
     * @param  array<int, string>        $headers  the column set to hash, in order
     * @param  array<int, string>        $ignore   columns to exclude (e.g. volatile timestamps)
     */
    public function canonicalHash(array $row, array $headers, array $ignore = []): string
    {
        $assoc = $this->toAssoc($row, $headers);

        $canonical = [];
        foreach ($headers as $header) {
            if ($header === '' || in_array($header, $ignore, true)) {
                continue;
            }
            $canonical[$header] = $this->canonicalValue($assoc[$header] ?? null);
        }

        return hash('xxh3', json_encode($canonical));
    }

    /**
     * Comparable string form of a cell value: numerics are reduced to their
     * shortest decimal form so formatting differences do not count as edits.
     */
    private function canonicalValue($value): string
    {
        $value = $this->formatValue($value);

        if (is_string($value) && (! is_numeric($value) || trim($value) !== $value)) {
            return $value;
        }

        $number = (float) $value;
        if (floor($number) === $number && abs($number) < 1e15) {
            return (string) (int) $number;
        }

        return rtrim(rtrim(sprintf('%.10F', $number), '0'), '.');
    }

    /**
     * Format a value for Google Sheets. Numbers stay numbers so a RAW write
     * stores a numeric cell (formulas and pivots can aggregate it); everything
     * else becomes a string. Override to change how a model's values are
     * written (it is used for the content hash too).
     */
    protected function formatValue($value): string|int|float
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        if (is_int($value) || is_float($value)) {
            return $value;
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
