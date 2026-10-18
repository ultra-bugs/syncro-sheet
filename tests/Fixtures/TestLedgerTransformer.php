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

use Zuko\SyncroSheet\Contracts\SheetExportable;
use Zuko\SyncroSheet\Services\DataTransformer;

/**
 * Per-model codec: the note is shown in upper case on the sheet and read back
 * in lower case, so the DB keeps lower case.
 */
class TestLedgerTransformer extends DataTransformer
{
    protected function transformRecord(SheetExportable $record): array
    {
        $row = parent::transformRecord($record);
        $row[1] = strtoupper((string) $row[1]);

        return $row;
    }

    protected function parseValue($value)
    {
        $value = parent::parseValue($value);

        return is_string($value) ? strtolower($value) : $value;
    }
}
