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

namespace Zuko\SyncroSheet\Contracts;

/**
 * Contract for models that can be imported FROM a Google Sheet back into the
 * database (Sheet -> DB). Rows are matched to records through a primary-key
 * column. The {@see \Zuko\SyncroSheet\Traits\InteractsWithSheet} trait provides
 * sensible defaults for every method.
 */
interface SheetImportable extends SheetBound
{
    /**
     * Name of the sheet header (column) that carries the model primary key.
     *
     * The value under this column is matched against the model key
     * ({@see \Illuminate\Database\Eloquent\Model::getKeyName()}) to decide
     * whether a row updates an existing record or creates a new one.
     */
    public function getSheetKeyColumn(): string;

    /**
     * 0-based index of the sheet column holding the primary key (A = 0).
     *
     * The key is read from and written to this cell by position, so the
     * column does not need a header. It is also where the id of a record
     * created from a sheet row without a key is written back.
     */
    public function getSheetKeyColumnIndex(): int;

    /**
     * Transform a single associative sheet row (header => value) into an array
     * of model attributes ready to be persisted.
     *
     * Return [] when the row is not a record (separator, total, blank line):
     * such a row without a key is never created, and for an existing record
     * nothing is updated.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public function fromSheetRow(array $row): array;
}
