<?php

namespace Zuko\SyncroSheet\Tests\Feature;

use Mockery;
use Zuko\SyncroSheet\Exceptions\SyncException;
use Zuko\SyncroSheet\Services\GoogleClient;
use Zuko\SyncroSheet\Services\SyncManager;
use Zuko\SyncroSheet\Tests\Fixtures\FakeProduct;
use Zuko\SyncroSheet\Tests\Fixtures\OneWayProduct;
use Zuko\SyncroSheet\Tests\TestCase;

class SyncManagerTest extends TestCase
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

    public function test_full_sync_creates_sync_state(): void
    {
        FakeProduct::create(['name' => 'Widget', 'price' => 9.99, 'sku' => 'W001']);
        FakeProduct::create(['name' => 'Gadget', 'price' => 19.99, 'sku' => 'G001']);

        $this->googleClient->shouldReceive('getHeaders')->andReturn(['Name', 'Price', 'SKU', 'DB_ID']);
        $this->googleClient->shouldReceive('appendWithHeaders')->once();
        $this->googleClient->shouldReceive('readSheet')->andReturn([]);

        $manager = $this->app->make(SyncManager::class);
        $state = $manager->fullSync(FakeProduct::class);

        $this->assertEquals('completed', $state->status);
        $this->assertEquals('to_sheet', $state->sync_direction);
        $this->assertEquals(2, $state->total_processed);
    }

    public function test_full_sync_with_direction(): void
    {
        FakeProduct::create(['name' => 'Widget', 'price' => 9.99, 'sku' => 'W001']);

        $this->googleClient->shouldReceive('getHeaders')->andReturn(['Name', 'Price', 'SKU', 'DB_ID']);
        $this->googleClient->shouldReceive('appendWithHeaders')->once();
        $this->googleClient->shouldReceive('readSheet')->andReturn([]);

        $manager = $this->app->make(SyncManager::class);
        $state = $manager->fullSync(FakeProduct::class, ['sync_direction' => 'bidirectional']);

        $this->assertEquals('bidirectional', $state->sync_direction);
    }

    public function test_validate_model_rejects_non_syncable(): void
    {
        $manager = $this->app->make(SyncManager::class);

        $this->expectException(SyncException::class);
        $this->expectExceptionMessage('must implement SheetSyncable');

        $manager->partialSync('Illuminate\Database\Eloquent\Model', [1]);
    }

    public function test_validate_model_rejects_nonexistent(): void
    {
        $manager = $this->app->make(SyncManager::class);

        $this->expectException(SyncException::class);
        $this->expectExceptionMessage('does not exist');

        $manager->partialSync('App\Models\DoesNotExist', [1]);
    }

    public function test_sync_from_sheet_works_with_bidirectional_syncable(): void
    {
        $this->googleClient->shouldReceive('readSheet')->andReturn([
            ['Name', 'Price', 'SKU', 'DB_ID'],
        ]);

        $manager = $this->app->make(SyncManager::class);
        $state = $manager->syncFromSheet(FakeProduct::class);

        $this->assertEquals('from_sheet', $state->sync_direction);
        $this->assertEquals('completed', $state->status);
    }

    public function test_sync_from_sheet_rejects_non_bidirectional(): void
    {
        $manager = $this->app->make(SyncManager::class);

        $this->expectException(SyncException::class);
        $this->expectExceptionMessage('must implement BidirectionalSyncable');

        $manager->syncFromSheet(OneWayProduct::class);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
