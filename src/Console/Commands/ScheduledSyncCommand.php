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

namespace Zuko\SyncroSheet\Console\Commands;

use Illuminate\Console\Command;
use Zuko\SyncroSheet\Services\SyncScheduler;

class ScheduledSyncCommand extends Command
{
    protected $signature = 'sheet:scheduled-sync';

    protected $description = 'Run the configured scheduled syncs via the configured driver';

    public function handle(SyncScheduler $scheduler): int
    {
        $driver = config('syncro-sheet.scheduler.driver', 'sync');
        $this->info("Running scheduled syncs (driver: {$driver})");

        try {
            $scheduler->run();

            $this->info('Scheduled syncs dispatched.');

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Scheduled sync failed: {$e->getMessage()}");

            return self::FAILURE;
        }
    }
}
