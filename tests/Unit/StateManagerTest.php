<?php

namespace Zuko\SyncroSheet\Tests\Unit;

use Mockery;
use Zuko\SyncroSheet\Services\StateManager;
use Zuko\SyncroSheet\Services\SyncLogger;
use Zuko\SyncroSheet\Tests\TestCase;

class StateManagerTest extends TestCase
{
    private StateManager $stateManager;

    protected function setUp(): void
    {
        parent::setUp();
        $logger = Mockery::mock(SyncLogger::class)->shouldIgnoreMissing();
        $this->stateManager = new StateManager($logger);
    }

    public function test_initialize_sync_creates_state(): void
    {
        $state = $this->stateManager->initializeSync(
            'App\\Models\\Product',
            'full',
            'append'
        );

        $this->assertNotNull($state->id);
        $this->assertEquals('App\\Models\\Product', $state->model_class);
        $this->assertEquals('full', $state->sync_type);
        $this->assertEquals('append', $state->sync_mode);
        $this->assertEquals('to_sheet', $state->sync_direction);
        $this->assertEquals('running', $state->status);
    }

    public function test_initialize_sync_with_direction(): void
    {
        $state = $this->stateManager->initializeSync(
            'App\\Models\\Product',
            'full',
            'append',
            'from_sheet'
        );

        $this->assertEquals('from_sheet', $state->sync_direction);
    }

    public function test_complete_sync_updates_state(): void
    {
        $state = $this->stateManager->initializeSync('App\\Models\\Product', 'full', 'append');

        $this->stateManager->completeSync($state, [
            'total_processed' => 50,
            'last_processed_id' => 100,
        ]);

        $state->refresh();
        $this->assertEquals('completed', $state->status);
        $this->assertEquals(50, $state->total_processed);
        $this->assertEquals(100, $state->last_processed_id);
        $this->assertNotNull($state->completed_at);
    }

    public function test_fail_sync_records_error(): void
    {
        $state = $this->stateManager->initializeSync('App\\Models\\Product', 'full', 'append');

        $this->stateManager->failSync($state, 'Connection timeout');

        $state->refresh();
        $this->assertEquals('failed', $state->status);
        $this->assertEquals('Connection timeout', $state->error_message);
    }

    public function test_record_batch_sync_creates_entries(): void
    {
        $state = $this->stateManager->initializeSync('App\\Models\\Product', 'full', 'append');

        $this->stateManager->recordBatchSync($state, [1, 2, 3]);

        $state->refresh();
        $this->assertEquals(3, $state->total_processed);
        $this->assertEquals(3, $state->last_processed_id);
        $this->assertCount(3, $state->entries);
    }

    public function test_record_batch_sync_with_row_number(): void
    {
        $state = $this->stateManager->initializeSync('App\\Models\\Product', 'full', 'append');

        $this->stateManager->recordBatchSyncWithRowNumber($state, [42], 5);

        $state->refresh();
        $entry = $state->entries->first();
        $this->assertEquals(42, $entry->record_id);
        $this->assertEquals(5, $entry->sheet_row_number);
    }

    public function test_get_last_successful_sync(): void
    {
        $this->stateManager->initializeSync('App\\Models\\Product', 'full', 'append');

        $state2 = $this->stateManager->initializeSync('App\\Models\\Product', 'full', 'append');
        $this->stateManager->completeSync($state2, ['total_processed' => 10, 'last_processed_id' => 10]);

        $last = $this->stateManager->getLastSuccessfulSync('App\\Models\\Product');

        $this->assertNotNull($last);
        $this->assertEquals($state2->id, $last->id);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
