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

namespace Zuko\SyncroSheet\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Zuko\SyncroSheet\Contracts\SheetImportable;
use Zuko\SyncroSheet\Contracts\SheetSyncable;
use Zuko\SyncroSheet\Traits\InteractsWithSheet;

/**
 * Two-way fixture model: exportable and importable.
 */
class TestProduct extends Model implements SheetImportable, SheetSyncable
{
    use InteractsWithSheet;

    protected $table = 'products';

    protected $guarded = [];

    public function getSheetIdentifier(): string
    {
        return 'test-sheet-id';
    }

    public function getSheetName(): string
    {
        return 'Products';
    }

    public function toSheetRow(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'price' => $this->price,
        ];
    }

    public function fromSheetRow(array $row): array
    {
        return [
            'name' => $row['name'] ?? null,
            'price' => $row['price'] ?? null,
        ];
    }

    // getSheetKeyColumn() comes from InteractsWithSheet and returns 'id'.
}
