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

interface SheetSyncable
{
    /**
     * Get the Google Sheet ID for this model
     */
    public function getSheetIdentifier(): string;

    /**
     * Get the sheet name within the Google Sheet
     */
    public function getSheetName(): string;

    /**
     * Transform the model instance to a sheet row
     */
    public function toSheetRow(): array;

    /**
     * Get the batch size for processing
     */
    public function getBatchSize(): ?int;

    /**
     * Get the fields used to identify unique records
     * If not implemented, system will use auto-detection
     */
    public function getSheetIdentifierFields(): ?array;

    /**
     * Get the sheet row ID for this model instance
     * Used for bi-directional sync
     */
    public function getSheetRowId(): ?string;

    /**
     * Set the sheet row ID for this model instance
     */
    public function setSheetRowId(string $rowId): void;
}
