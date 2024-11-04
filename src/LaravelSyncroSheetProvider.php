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

namespace Zuko\SyncroSheet;

use Illuminate\Foundation\AliasLoader;
use Illuminate\Support\ServiceProvider;
use Zuko\SyncroSheet\Services\BatchProcessor;
use Zuko\SyncroSheet\Services\GoogleClient;
use Zuko\SyncroSheet\Services\StateManager;
use Zuko\SyncroSheet\Services\SyncManager;

class LaravelSyncroSheetProvider extends ServiceProvider
{
    public function boot()
    {
        $this->publishes([
            __DIR__.'/../config/syncro-sheet.php' => config_path('syncro-sheet.php'),
        ], 'config');

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([
                Console\Commands\SheetSyncCommand::class,
            ]);
        }
    }

    public function register()
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/syncro-sheet.php', 'syncro-sheet'
        );

        $this->app->singleton(SyncManager::class);
        $this->app->singleton(StateManager::class);
        $this->app->singleton(BatchProcessor::class);
        $this->app->singleton(GoogleClient::class);

        // Register facade
        $loader = AliasLoader::getInstance();
        $loader->alias('SyncroSheet', \Zuko\SyncroSheet\Facades\SyncroSheet::class);
    }
}
