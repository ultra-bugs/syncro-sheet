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

namespace Zuko\SyncroSheet\Tests\Feature;

use Illuminate\Support\Facades\Queue;
use Zuko\SyncroSheet\Jobs\ImportBatchJob;
use Zuko\SyncroSheet\Jobs\PartialSyncJob;
use Zuko\SyncroSheet\Jobs\ReconcileApplyJob;
use Zuko\SyncroSheet\Models\SyncState;
use Zuko\SyncroSheet\Services\GoogleClient;
use Zuko\SyncroSheet\Services\SyncScheduler;
use Zuko\SyncroSheet\Tests\Fixtures\FakeGoogleClient;
use Zuko\SyncroSheet\Tests\Fixtures\TestProduct;
use Zuko\SyncroSheet\Tests\TestCase;

class SchedulerTest extends TestCase
{
    private FakeGoogleClient $fakeClient;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeClient = new FakeGoogleClient();
        $this->app->instance(GoogleClient::class, $this->fakeClient);
    }

    private function scheduler(): SyncScheduler
    {
        return app(SyncScheduler::class);
    }

    public function test_sync_driver_runs_bidirectional_inline(): void
    {
        config([
            'syncro-sheet.scheduler.driver' => 'sync',
            'syncro-sheet.scheduler.models' => [TestProduct::class => 'both'],
        ]);

        TestProduct::create(['id' => 1, 'name' => 'A', 'price' => 10]);
        $this->fakeClient->sheetValues = [['id', 'name', 'price'], ['1', 'A', '10']];

        $this->scheduler()->run();

        $this->assertDatabaseHas('sync_states', [
            'model_class' => TestProduct::class,
            'direction' => 'bidirectional',
            'status' => 'completed',
        ]);
    }

    public function test_queue_driver_fans_export_out_to_partial_sync_jobs(): void
    {
        Queue::fake();
        config([
            'syncro-sheet.scheduler.driver' => 'queue',
            'syncro-sheet.scheduler.models' => [TestProduct::class => 'export'],
        ]);

        TestProduct::create(['id' => 1, 'name' => 'A', 'price' => 10]);
        TestProduct::create(['id' => 2, 'name' => 'B', 'price' => 20]);

        $this->scheduler()->run();

        Queue::assertPushed(PartialSyncJob::class, 1);
    }

    public function test_queue_driver_fans_import_out_to_import_batch_jobs(): void
    {
        Queue::fake();
        config([
            'syncro-sheet.scheduler.driver' => 'queue',
            'syncro-sheet.scheduler.models' => [TestProduct::class => 'import'],
        ]);

        $this->fakeClient->sheetValues = [
            ['id', 'name', 'price'],
            ['1', 'One', '1'],
            ['2', 'Two', '2'],
        ];

        $this->scheduler()->run();

        Queue::assertPushed(ImportBatchJob::class, 1);
    }

    public function test_queue_driver_decides_then_dispatches_reconcile_apply_jobs(): void
    {
        Queue::fake();
        config([
            'syncro-sheet.scheduler.driver' => 'queue',
            'syncro-sheet.scheduler.models' => [TestProduct::class => 'both'],
        ]);

        // DB has a record, sheet carries it too: first run (no baseline) => a
        // decision is produced and fanned out as an apply job.
        TestProduct::create(['id' => 1, 'name' => 'A', 'price' => 10]);
        $this->fakeClient->sheetValues = [['id', 'name', 'price'], ['1', 'A', '10']];

        $this->scheduler()->run();

        Queue::assertPushed(ReconcileApplyJob::class, 1);
    }

    public function test_reconcile_apply_job_applies_a_decision(): void
    {
        // A sheet-wins decision applied by the job upserts the DB record.
        $decisions = [[
            'pk' => 7,
            'action' => 'import',
            'payload' => ['assoc' => ['id' => '7', 'name' => 'FromJob', 'price' => '70']],
            'hash' => 'x',
        ]];

        (new ReconcileApplyJob(TestProduct::class, $decisions))
            ->handle(app(\Zuko\SyncroSheet\Services\Reconciler::class));

        $this->assertDatabaseHas('products', ['id' => 7, 'name' => 'FromJob', 'price' => 70]);
        $this->assertDatabaseHas('sync_snapshots', ['model_class' => TestProduct::class, 'record_id' => 7]);
    }
}
