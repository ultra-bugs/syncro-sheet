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

namespace Zuko\SyncroSheet\Services\Drivers;

use Zuko\SyncroSheet\Contracts\SyncDriver;
use Zuko\SyncroSheet\Services\SyncManager;

/**
 * Runs the sync inline (in-process), straight through SyncManager. Default driver.
 */
class InlineDriver implements SyncDriver
{
    public function __construct(private readonly SyncManager $syncManager)
    {
    }

    public function dispatch(string $modelClass, string $direction, array $options = []): void
    {
        match ($direction) {
            SyncManager::DIRECTION_IMPORT => $this->syncManager->importFull($modelClass, $options),
            SyncManager::DIRECTION_BIDIRECTIONAL => $this->syncManager->sync($modelClass, $options),
            default => $this->syncManager->fullSync($modelClass, $options),
        };
    }
}
