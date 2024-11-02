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

namespace Zuko\SyncroSheet\Facades;

use Illuminate\Support\Facades\Facade;
use Zuko\SyncroSheet\Services\SyncManager;

/**
 * @method static \Zuko\SyncroSheet\Models\SyncState fullSync(string $modelClass)
 * @method static \Zuko\SyncroSheet\Models\SyncState partialSync(string $modelClass, array $recordIds)
 * @method static \Zuko\SyncroSheet\Models\SyncState|null getLastSync(string $modelClass)
 * 
 * @see \Zuko\SyncroSheet\Services\SyncManager
 */
class SyncroSheet extends Facade
{
    protected static function getFacadeAccessor()
    {
        return SyncManager::class;
    }
} 
