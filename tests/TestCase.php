<?php

namespace Zuko\SyncroSheet\Tests;

use Orchestra\Testbench\TestCase as BaseTestCase;
use Zuko\SyncroSheet\LaravelSyncroSheetProvider;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            LaravelSyncroSheetProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('syncro-sheet.defaults', [
            'batch_size' => 10,
            'sync_mode' => 'append',
            'sync_direction' => 'to_sheet',
            'timeout' => 600,
            'retries' => 3,
        ]);

        $app['config']->set('syncro-sheet.sheets.rate_limit', [
            'max_requests' => 100,
            'per_seconds' => 60,
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }
}
