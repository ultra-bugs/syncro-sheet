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

namespace Zuko\SyncroSheet\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Zuko\SyncroSheet\Models\SyncEntry;
use Zuko\SyncroSheet\Models\SyncState;
use Zuko\SyncroSheet\Services\GoogleClient;
use Zuko\SyncroSheet\Services\SyncLogger;
use Zuko\SyncroSheet\Services\SyncManager;
use Zuko\SyncroSheet\Services\TrackingDatabase;
use Zuko\SyncroSheet\Tests\Fixtures\FakeGoogleClient;
use Zuko\SyncroSheet\Tests\Fixtures\TestProduct;
use Zuko\SyncroSheet\Tests\TestCase;

class TrackingDatabaseTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        parent::setUp();
        $this->file = sys_get_temp_dir().'/syncro-tracking-'.uniqid().'.sqlite';
    }

    protected function tearDown(): void
    {
        foreach ([$this->file, $this->file.'.lock', $this->file.'-wal', $this->file.'-shm'] as $path) {
            @unlink($path);
        }
        config(['syncro-sheet.database.resolved_connection' => 'testbench']);

        parent::tearDown();
    }

    private function useDedicatedSqlite(): TrackingDatabase
    {
        config(['syncro-sheet.database.connection' => null, 'syncro-sheet.database.sqlite_database' => $this->file]);
        $tracking = app(TrackingDatabase::class);
        $tracking->resolve();
        $tracking->ensureSchema();

        return $tracking;
    }

    public function test_explicit_connection_wins(): void
    {
        $this->assertSame('testbench', TrackingDatabase::connectionName());
        $this->assertSame('testbench', (new SyncState)->getConnectionName());
    }

    public function test_dedicated_sqlite_gets_its_own_schema_and_holds_the_tracking_rows(): void
    {
        $tracking = $this->useDedicatedSqlite();

        $this->assertTrue($tracking->usesDedicatedSqlite());
        $this->assertFileExists($this->file);
        foreach (['sync_states', 'sync_entries', 'sync_snapshots', 'migrations'] as $table) {
            $this->assertTrue(Schema::connection(TrackingDatabase::CONNECTION)->hasTable($table), $table);
        }

        SyncState::create(['model_class' => 'X', 'sync_type' => 'full', 'status' => 'running', 'started_at' => now()]);
        $this->assertSame(1, DB::connection(TrackingDatabase::CONNECTION)->table('sync_states')->count());

        $tracking->migrate(); // idempotent: nothing pending
        $this->assertSame(1, DB::connection(TrackingDatabase::CONNECTION)->table('sync_states')->count());
    }

    public function test_without_pdo_sqlite_it_falls_back_and_warns_on_both_channels_once(): void
    {
        config(['syncro-sheet.database.connection' => null]);
        $packageLog = Mockery::mock(SyncLogger::class);
        $packageLog->shouldReceive('warning')->once()->withArgs(fn ($message) => str_contains($message, 'pdo_sqlite'));
        Log::shouldReceive('warning')->once()->withArgs(fn ($message) => str_contains($message, 'pdo_sqlite'));

        $tracking = new class($packageLog) extends TrackingDatabase
        {
            protected function sqliteAvailable(): bool
            {
                return false;
            }
        };

        $this->assertNull($tracking->resolve());
        $this->assertNull($tracking->resolve(), 'second boot: same choice, no second warning');
        $this->assertFalse($tracking->usesDedicatedSqlite());
    }

    public function test_recent_export_filter_works_across_connections(): void
    {
        $this->useDedicatedSqlite();
        $fake = new FakeGoogleClient();
        $this->app->instance(GoogleClient::class, $fake);
        TestProduct::create(['id' => 1, 'name' => 'A', 'price' => 1]);
        TestProduct::create(['id' => 2, 'name' => 'B', 'price' => 2]);

        app(SyncManager::class)->fullSync(TestProduct::class);
        app(SyncManager::class)->fullSync(TestProduct::class);

        $this->assertCount(2, $fake->appended, 'second export skips the records exported moments ago');
        $this->assertSame(2, SyncEntry::count());
    }

    public function test_recent_export_filter_matches_the_record_key_not_the_entry_id(): void
    {
        $fake = new FakeGoogleClient();
        $this->app->instance(GoogleClient::class, $fake);
        // Entries of another model shift sync_entries.id away from record_id.
        $state = SyncState::create(['model_class' => 'Other', 'sync_type' => 'full', 'status' => 'completed']);
        foreach ([7, 8, 9] as $id) {
            SyncEntry::create(['sync_state_id' => $state->id, 'model_class' => 'Other', 'record_id' => $id,
                'synced_at' => now(), 'sync_type' => 'full', 'status' => 'success']);
        }
        TestProduct::create(['id' => 1, 'name' => 'A', 'price' => 1]);
        TestProduct::create(['id' => 2, 'name' => 'B', 'price' => 2]);

        app(SyncManager::class)->fullSync(TestProduct::class);
        app(SyncManager::class)->fullSync(TestProduct::class);

        $this->assertCount(2, $fake->appended);
    }
}
