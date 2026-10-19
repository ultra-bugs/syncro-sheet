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

use Zuko\SyncroSheet\Traits\InteractsWithSheet;

/**
 * TestLedger with the optional model hooks consulted by InteractsWithSheet.
 */
class TestHookedLedger extends TestLedger
{
    use InteractsWithSheet;

    public function fromSheetRow(array $row): array
    {
        return parent::fromSheetRow($row);
    }

    /** Rows whose note starts with "#" are comments, not ledger entries. */
    public function shouldImportSheetRow(array $row): bool
    {
        return ! str_starts_with((string) ($row['note'] ?? ''), '#');
    }

    public function sheetTransformer(): string
    {
        return TestLedgerTransformer::class;
    }
}
