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

namespace Zuko\SyncroSheet\Traits;

use Zuko\SyncroSheet\Exceptions\SyncException;
use Zuko\SyncroSheet\Services\DataTransformer;

/**
 * Convenience defaults for {@see \Zuko\SyncroSheet\Contracts\SheetImportable}.
 *
 * Assumes sheet headers match the model attribute names one-to-one. Override
 * a method on the model when the sheet layout differs from the table, or
 * define the optional hooks it consults: shouldImportSheetRow() (may a row
 * without a key become a record?) and sheetTransformer() (how the model's data
 * is written to the sheet).
 */
trait InteractsWithSheet
{
    /**
     * Columns written by the sync engine for bookkeeping; never fed back into
     * the model as attributes on import.
     */
    protected array $sheetMetaColumns = ['_record_hash', '_row_index'];

    /**
     * Default primary-key column name in the sheet.
     */
    public function getSheetKeyColumn(): string
    {
        return $this->getKeyName();
    }

    /**
     * Default key position: where getSheetKeyColumn() sits in the exported row
     * (defaultSheetHeaders(), or the keys of toSheetRow()).
     */
    public function getSheetKeyColumnIndex(): int
    {
        $headers = method_exists($this, 'defaultSheetHeaders')
            ? $this->defaultSheetHeaders()
            : array_keys($this->toSheetRow());

        $index = array_search($this->getSheetKeyColumn(), array_values($headers), true);
        if ($index === false) {
            throw new SyncException(
                'Cannot locate the key column ['.$this->getSheetKeyColumn().'] of '.static::class
                .'; override getSheetKeyColumnIndex().'
            );
        }

        return $index;
    }

    /**
     * Sheet -> DB: may this row, which has no key yet, become a new record?
     *
     * Asks the model's shouldImportSheetRow(array $row): bool when it defines
     * one; otherwise every row may (it still needs a non-empty fromSheetRow()).
     *
     * @param  array<string, mixed>  $row  parsed sheet row (header => value)
     */
    public function canImportSheetRow(array $row): bool
    {
        return method_exists($this, 'shouldImportSheetRow')
            ? (bool) $this->shouldImportSheetRow($row)
            : true;
    }

    /**
     * DB -> Sheet: the transformer that decides how this model's toSheetRow()
     * data is written (and read back and hashed).
     *
     * Uses the model's sheetTransformer(): DataTransformer|class-string when
     * it defines one; otherwise the shared DataTransformer.
     */
    public function getSheetTransformer(): DataTransformer
    {
        $transformer = method_exists($this, 'sheetTransformer')
            ? $this->sheetTransformer()
            : DataTransformer::class;

        return $transformer instanceof DataTransformer ? $transformer : app($transformer);
    }

    /**
     * Default reverse transform: drop the meta columns and treat the remaining
     * header => value pairs as model attributes.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public function fromSheetRow(array $row): array
    {
        foreach ($this->sheetMetaColumns as $metaColumn) {
            unset($row[$metaColumn]);
        }

        return $row;
    }
}
