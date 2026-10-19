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
 *          * -  Copyright © 2026 (Z) Programing  - *
 *          *    -  -  All Rights Reserved  -  -    *
 *          * * * * * * * * * * * * * * * * * * * * *
 */

namespace Zuko\SyncroSheet\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Zuko\SyncroSheet\Contracts\SheetSyncable;

/**
 * Real-world shaped fixture: a positional toSheetRow() with blank columns, a
 * guarded primary key, soft deletes, a decimal cast, a JSON column merged on
 * import and a sheet-managed (formula) column.
 *
 * Layout: A amount | B note | C (blank) | D total (formula) | E id
 */
class TestLedger extends Model implements SheetSyncable
{
    use SoftDeletes;

    protected $table = 'ledgers';

    protected $fillable = ['amount', 'note', 'meta'];

    protected $casts = ['amount' => 'decimal:2', 'meta' => 'array'];

    public function getSheetIdentifier(): string
    {
        return 'ledger-sheet-id';
    }

    public function getSheetName(): string
    {
        return 'Ledger';
    }

    public function defaultSheetHeaders(): array
    {
        return ['amount', 'note', '', 'total', 'id'];
    }

    public function toSheetRow(): array
    {
        return [(float) $this->amount, (string) $this->note, '', (float) $this->amount, $this->id];
    }

    public function getSheetKeyColumn(): string
    {
        return 'id';
    }

    public function getSheetKeyColumnIndex(): int
    {
        return 4;
    }

    public function syncReadOnlyColumns(): array
    {
        return ['total'];
    }

    public function fromSheetRow(array $row): array
    {
        if (($row['amount'] ?? null) === null) {
            return []; // separator / blank line: not a ledger row
        }

        return [
            'amount' => $row['amount'] ?? null,
            'note' => $row['note'] ?? null,
            'meta' => array_merge($this->meta ?? [], ['note_from_sheet' => true]),
        ];
    }
}
