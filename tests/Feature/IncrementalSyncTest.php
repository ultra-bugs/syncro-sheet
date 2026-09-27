<?php

namespace Zuko\SyncroSheet\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Mockery;
use Zuko\SyncroSheet\Services\GoogleClient;
use Zuko\SyncroSheet\Services\SyncManager;
use Zuko\SyncroSheet\Tests\Fixtures\FakeProduct;
use Zuko\SyncroSheet\Tests\TestCase;

class IncrementalSyncTest extends TestCase
{
    private GoogleClient $googleClient;

    protected function setUp(): void
    {
        parent::setUp();

        $this->googleClient = Mockery::mock(GoogleClient::class);
        $this->googleClient->shouldIgnoreMissing();
        $this->app->instance(GoogleClient::class, $this->googleClient);
    }

    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();
        $this->loadMigrationsFrom(__DIR__.'/../Fixtures');
    }

    public function test_incremental_sync_creates_state_with_incremental_type(): void
    {
        FakeProduct::create(['name' => 'Widget', 'price' => 9.99, 'sku' => 'W001']);

        $this->googleClient->shouldReceive('getHeaders')->andReturn(['Name', 'Price', 'SKU', 'DB_ID']);
        $this->googleClient->shouldReceive('readSheet')->andReturn([]);

        $manager = $this->app->make(SyncManager::class);
        $state = $manager->incrementalSync(FakeProduct::class);

        $this->assertEquals('incremental', $state->sync_type);
        $this->assertEquals('completed', $state->status);
    }

    public function test_incremental_sync_stores_content_hash(): void
    {
        $product = FakeProduct::create(['name' => 'Widget', 'price' => 9.99, 'sku' => 'W001']);

        $this->googleClient->shouldReceive('getHeaders')->andReturn(['Name', 'Price', 'SKU', 'DB_ID']);
        $this->googleClient->shouldReceive('readSheet')->andReturn([]);

        $manager = $this->app->make(SyncManager::class);
        $manager->incrementalSync(FakeProduct::class);

        $entry = DB::table('sync_entries')
            ->where('record_id', $product->id)
            ->first();

        $this->assertNotNull($entry->content_hash);
        $this->assertSame(32, strlen($entry->content_hash));
    }

    public function test_incremental_sync_skips_unchanged_records(): void
    {
        $product = FakeProduct::create(['name' => 'Widget', 'price' => 9.99, 'sku' => 'W001']);

        $this->googleClient->shouldReceive('getHeaders')->andReturn(['Name', 'Price', 'SKU', 'DB_ID']);
        $this->googleClient->shouldReceive('readSheet')->andReturn([]);

        $manager = $this->app->make(SyncManager::class);

        $state1 = $manager->incrementalSync(FakeProduct::class);
        $this->assertEquals(1, $state1->total_processed);

        $state2 = $manager->incrementalSync(FakeProduct::class);
        $this->assertEquals(0, $state2->total_processed);
    }

    public function test_incremental_sync_detects_changed_records(): void
    {
        $product = FakeProduct::create(['name' => 'Widget', 'price' => 9.99, 'sku' => 'W001']);

        $this->googleClient->shouldReceive('getHeaders')->andReturn(['Name', 'Price', 'SKU', 'DB_ID']);
        $this->googleClient->shouldReceive('readSheet')->andReturn([]);

        $manager = $this->app->make(SyncManager::class);

        $state1 = $manager->incrementalSync(FakeProduct::class);
        $this->assertEquals(1, $state1->total_processed);

        $product->update(['price' => 19.99]);

        $state2 = $manager->incrementalSync(FakeProduct::class);
        $this->assertEquals(1, $state2->total_processed);
    }

    public function test_incremental_sync_detects_new_records(): void
    {
        FakeProduct::create(['name' => 'Widget', 'price' => 9.99, 'sku' => 'W001']);

        $this->googleClient->shouldReceive('getHeaders')->andReturn(['Name', 'Price', 'SKU', 'DB_ID']);
        $this->googleClient->shouldReceive('readSheet')->andReturn([]);

        $manager = $this->app->make(SyncManager::class);

        $state1 = $manager->incrementalSync(FakeProduct::class);
        $this->assertEquals(1, $state1->total_processed);

        FakeProduct::create(['name' => 'Gadget', 'price' => 29.99, 'sku' => 'G001']);

        $state2 = $manager->incrementalSync(FakeProduct::class);
        $this->assertEquals(1, $state2->total_processed);
    }

    public function test_full_sync_also_stores_content_hash(): void
    {
        $product = FakeProduct::create(['name' => 'Widget', 'price' => 9.99, 'sku' => 'W001']);

        $this->googleClient->shouldReceive('getHeaders')->andReturn(['Name', 'Price', 'SKU', 'DB_ID']);
        $this->googleClient->shouldReceive('appendWithHeaders')->once();
        $this->googleClient->shouldReceive('readSheet')->andReturn([]);

        $manager = $this->app->make(SyncManager::class);
        $manager->fullSync(FakeProduct::class);

        $entry = DB::table('sync_entries')
            ->where('record_id', $product->id)
            ->first();

        $this->assertNotNull($entry->content_hash);
    }

    public function test_incremental_sync_discovers_new_sheet_rows_for_bidirectional(): void
    {
        $this->googleClient->shouldReceive('readSheet')->andReturn([
            ['Name', 'Price', 'SKU', 'DB_ID'],
            ['NewProduct', '49.99', 'NP001', ''],
        ]);
        $this->googleClient->shouldReceive('getHeaders')->andReturn(['Name', 'Price', 'SKU', 'DB_ID']);

        $manager = $this->app->make(SyncManager::class);
        $state = $manager->incrementalSync(FakeProduct::class, [
            'sync_direction' => 'bidirectional',
        ]);

        $this->assertEquals('completed', $state->status);

        $created = FakeProduct::where('name', 'NewProduct')->first();
        $this->assertNotNull($created);
        $this->assertEquals(49.99, (float) $created->price);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
