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

/**
 * Convenience defaults for {@see \Zuko\SyncroSheet\Contracts\SheetImportable}.
 *
 * Assumes sheet headers match the model attribute names one-to-one. Override
 * either method on the model when the sheet layout differs from the table.
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
