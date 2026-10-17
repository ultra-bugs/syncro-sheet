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
                          {--ids=* : Specific record IDs for partial sync}
                          {--M|mode= : Sync mode (append/replace)}
                          {--D|direction=export : Sync direction (export: DB->Sheet, import: Sheet->DB, both: two-way reconcile)}
                          {--F|force : using replace mode}';

    protected $description = 'Sync model data with Google Sheets';

    public function handle(SyncManager $syncManager): int
    {
        $modelClass = $this->argument('model');

        // Add namespace if not provided
        if (! str_contains($modelClass, '\\')) {
            $modelClass = 'App\\Models\\'.$modelClass;
        }

        $direction = $this->option('direction') ?: SyncManager::DIRECTION_EXPORT;
        $ids = $this->option('ids');
        $mode = $this->option('mode');
        if (! $mode && $this->option('force')) {
            $mode = 'replace';
        }

        try {
            $ids = empty($ids)
                ? []
                : (is_array($ids) ? $ids : explode(',', $ids[0]));

            $syncState = match ($direction) {
                SyncManager::DIRECTION_IMPORT => $this->runImport($syncManager, $modelClass, $ids, $mode),
                SyncManager::DIRECTION_EXPORT => $this->runExport($syncManager, $modelClass, $ids, $mode),
                'both', SyncManager::DIRECTION_BIDIRECTIONAL => $this->runBidirectional($syncManager, $modelClass, $mode),
                default => throw new \InvalidArgumentException(
                    "Unsupported direction [{$direction}]. Use 'export', 'import' or 'both'."
                ),
            };

            $this->info("Sync completed! Processed {$syncState->total_processed} records.");

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Sync failed: {$e->getMessage()}");

            return self::FAILURE;
        }
    }

    private function runExport(SyncManager $syncManager, string $modelClass, array $ids, ?string $mode)
    {
        if (empty($ids)) {
            $this->info("Starting full export for {$modelClass}");

            return $syncManager->fullSync($modelClass, ['write_mode' => $mode]);
        }

        $this->info("Starting partial export for {$modelClass} with IDs: ".implode(', ', $ids));

        return $syncManager->partialSync($modelClass, $ids, ['write_mode' => $mode]);
    }

    private function runImport(SyncManager $syncManager, string $modelClass, array $ids, ?string $mode)
    {
        if (empty($ids)) {
            $this->info("Starting full import for {$modelClass}");

            return $syncManager->importFull($modelClass, ['write_mode' => $mode]);
        }

        $this->info("Starting partial import for {$modelClass} with IDs: ".implode(', ', $ids));

        return $syncManager->importPartial($modelClass, $ids, ['write_mode' => $mode]);
    }

    private function runBidirectional(SyncManager $syncManager, string $modelClass, ?string $mode)
    {
        // Reconcile is always a full pass; --ids is ignored here.
        $this->info("Starting bidirectional sync for {$modelClass}");

        return $syncManager->sync($modelClass, ['write_mode' => $mode]);
    }
}
