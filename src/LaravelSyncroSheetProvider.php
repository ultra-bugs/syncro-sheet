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

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\AliasLoader;
use Illuminate\Support\ServiceProvider;
use Zuko\SyncroSheet\Services\Exporter;
use Zuko\SyncroSheet\Services\GoogleClient;
use Zuko\SyncroSheet\Services\Importer;
use Zuko\SyncroSheet\Services\Reconciler;
use Zuko\SyncroSheet\Services\StateManager;
use Zuko\SyncroSheet\Services\SyncManager;
use Zuko\SyncroSheet\Services\SyncScheduler;

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
                Console\Commands\ScheduledSyncCommand::class,
            ]);
        }

        $this->registerSchedule();
    }

    /**
     * Register the scheduled-sync command on the host scheduler when enabled.
     */
    private function registerSchedule(): void
    {
        if (! config('syncro-sheet.scheduler.enabled', false)) {
            return;
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $event = $schedule->command('sheet:scheduled-sync');
            $frequency = (string) config('syncro-sheet.scheduler.frequency', 'everyFifteenMinutes');

            // A cron expression has spaces; a bare method name does not.
            if (str_contains($frequency, ' ')) {
                $event->cron($frequency);
            } elseif (method_exists($event, $frequency)) {
                $event->{$frequency}();
            } else {
                $event->everyFifteenMinutes();
            }
        });
    }

    public function register()
    {
        $this->mergeConfigFrom(__DIR__.'/../config/syncro-sheet.php',
            'syncro-sheet');
        // Ensure google config is available
        if (! $this->app->configurationIsCached()) {
            $revolutionConfig = base_path('vendor/revolution/laravel-google-sheets/config/google.php');
            if (! file_exists($revolutionConfig)) {
                $this->mergeConfigFrom(__DIR__.'/../vendor/revolution/laravel-google-sheets/config/google.php',
                    'google');
            } else {
                $this->mergeConfigFrom($revolutionConfig,
                    'google');
            }

        }
        $this->app->singleton(SyncManager::class);
        $this->app->singleton(StateManager::class);
        $this->app->singleton(Exporter::class);
        $this->app->singleton(Importer::class);
        $this->app->singleton(Reconciler::class);
        $this->app->singleton(GoogleClient::class);
        $this->app->singleton(SyncScheduler::class);
        // Register facade
        $loader = AliasLoader::getInstance();
        $loader->alias('SyncroSheet', \Zuko\SyncroSheet\Facades\SyncroSheet::class);
    }
}
