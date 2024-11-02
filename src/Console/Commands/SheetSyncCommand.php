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
use Zuko\SyncroSheet\Services\SyncManager;

class SheetSyncCommand extends Command
{
    protected $signature = 'sheet:sync 
                          {model : The model class to sync}
                          {--ids=* : Specific record IDs for partial sync}';

    protected $description = 'Sync model data with Google Sheets';

    public function handle(SyncManager $syncManager): int
    {
        $modelClass = $this->argument('model');
        
        // Add namespace if not provided
        if (!str_contains($modelClass, '\\')) {
            $modelClass = 'App\\Models\\' . $modelClass;
        }

        $ids = $this->option('ids');
        
        try {
            if (empty($ids)) {
                $this->info("Starting full sync for {$modelClass}");
                $syncState = $syncManager->fullSync($modelClass);
            } else {
                $ids = is_array($ids) ? $ids : explode(',', $ids[0]);
                $this->info("Starting partial sync for {$modelClass} with IDs: " . implode(', ', $ids));
                $syncState = $syncManager->partialSync($modelClass, $ids);
            }

            $this->info("Sync completed! Processed {$syncState->total_processed} records.");
            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Sync failed: {$e->getMessage()}");
            return self::FAILURE;
        }
    }
} 
