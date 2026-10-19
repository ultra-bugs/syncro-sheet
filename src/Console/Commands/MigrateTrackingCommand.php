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
use Zuko\SyncroSheet\Services\TrackingDatabase;

class MigrateTrackingCommand extends Command
{
    protected $signature = 'syncro-sheet:migrate';

    protected $description = 'Run pending syncro-sheet migrations on its dedicated SQLite tracking database';

    public function handle(TrackingDatabase $tracking): int
    {
        if (! $tracking->usesDedicatedSqlite()) {
            $this->info('Tracking tables use connection ['.(TrackingDatabase::connectionName() ?? config('database.default'))
                .'], migrated with the app: run .');

            return self::SUCCESS;
        }

        $tracking->migrate();
        $this->info('Tracking database is up to date: '.config('database.connections.'.TrackingDatabase::CONNECTION.'.database'));

        return self::SUCCESS;
    }
}
