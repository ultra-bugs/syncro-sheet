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
use Zuko\SyncroSheet\Models\SyncState;
use Zuko\SyncroSheet\Services\GoogleClient;
use Zuko\SyncroSheet\Services\SyncManager;
use Zuko\SyncroSheet\Tests\Fixtures\FakeGoogleClient;
use Zuko\SyncroSheet\Tests\Fixtures\TestProduct;
use Zuko\SyncroSheet\Tests\TestCase;

class ReconcileTest extends TestCase
{
    private FakeGoogleClient $fakeClient;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->fakeClient = new FakeGoogleClient();
        $this->app->instance(GoogleClient::class, $this->fakeClient);
    }

    private function sync(): SyncState
    {
        return app(SyncManager::class)->sync(TestProduct::class);
    }

    private function resetCapture(): void
    {
        $this->fakeClient->appended = [];
        $this->fakeClient->updatedByIndex = [];
    }

    public function test_only_db_changed_updates_the_sheet_row(): void
    {
        TestProduct::create(['id' => 1, 'name' => 'A', 'price' => 10]);
        $this->fakeClient->sheetValues = [['id', 'name', 'price'], ['1', 'A', '10']];
        $this->sync();            // seed baseline (both sides equal)
        $this->resetCapture();

        TestProduct::find(1)->update(['name' => 'A2']); // only DB changes

        $this->sync();

        $this->assertArrayHasKey(2, $this->fakeClient->updatedByIndex);
        $this->assertContains('A2', $this->fakeClient->updatedByIndex[2]);
        $this->assertSame([], $this->fakeClient->appended);
        $this->assertSame('A2', TestProduct::find(1)->name);
    }

    public function test_only_sheet_changed_upserts_the_record(): void
    {
        TestProduct::create(['id' => 1, 'name' => 'A', 'price' => 10]);
        $this->fakeClient->sheetValues = [['id', 'name', 'price'], ['1', 'A', '10']];
        $this->sync();
        $this->resetCapture();

        $this->fakeClient->sheetValues = [['id', 'name', 'price'], ['1', 'SheetEdit', '10']]; // only sheet changes

        $this->sync();

        $this->assertSame('SheetEdit', TestProduct::find(1)->name);
        $this->assertSame([], $this->fakeClient->updatedByIndex); // sheet won -> no write-back
    }

    public function test_conflict_resolves_to_db_when_db_is_source_of_truth(): void
    {
        TestProduct::create(['id' => 1, 'name' => 'A', 'price' => 10]);
        $this->fakeClient->sheetValues = [['id', 'name', 'price'], ['1', 'A', '10']];
        $this->sync();
        $this->resetCapture();

        // Both sides change the same record.
        TestProduct::find(1)->update(['name' => 'DBWins']);
        $this->fakeClient->sheetValues = [['id', 'name', 'price'], ['1', 'SheetWins', '10']];

        $this->sync(); // default source_of_truth = db

        $this->assertContains('DBWins', $this->fakeClient->updatedByIndex[2]);
        $this->assertSame('DBWins', TestProduct::find(1)->name);
    }

    public function test_conflict_resolves_to_sheet_when_sheet_is_source_of_truth(): void
    {
        TestProduct::create(['id' => 1, 'name' => 'A', 'price' => 10]);
        $this->fakeClient->sheetValues = [['id', 'name', 'price'], ['1', 'A', '10']];
        $this->sync();
        $this->resetCapture();

        config(['syncro-sheet.defaults.source_of_truth' => 'sheet']);
        TestProduct::find(1)->update(['name' => 'DBChange']);
        $this->fakeClient->sheetValues = [['id', 'name', 'price'], ['1', 'SheetChange', '10']];

        $this->sync();

        $this->assertSame('SheetChange', TestProduct::find(1)->name);
        $this->assertSame([], $this->fakeClient->updatedByIndex); // sheet won -> no sheet write
    }

    public function test_record_only_in_db_is_appended_to_the_sheet(): void
    {
        TestProduct::create(['id' => 2, 'name' => 'OnlyDb', 'price' => 5]);
        $this->fakeClient->sheetValues = [['id', 'name', 'price']]; // header only

        $this->sync();

        $this->assertCount(1, $this->fakeClient->appended);
        $this->assertContains('OnlyDb', array_values($this->fakeClient->appended[0]));
        $this->assertSame('OnlyDb', TestProduct::find(2)->name);
    }

    public function test_record_only_in_sheet_is_created_in_db(): void
    {
        $this->fakeClient->sheetValues = [['id', 'name', 'price'], ['3', 'FromSheet', '7']];

        $this->sync();

        $this->assertDatabaseHas('products', ['id' => 3, 'name' => 'FromSheet', 'price' => 7]);
        $this->assertSame([], $this->fakeClient->updatedByIndex); // created in DB, nothing pushed to sheet
    }

    public function test_second_identical_sync_is_a_noop(): void
    {
        TestProduct::create(['id' => 1, 'name' => 'A', 'price' => 10]);
        $this->fakeClient->sheetValues = [['id', 'name', 'price'], ['1', 'A', '10']];

        $state = $this->sync();
        $this->assertSame('bidirectional', $state->direction);
        $this->assertSame('completed', $state->status);
        $this->assertDatabaseHas('sync_snapshots', ['model_class' => TestProduct::class, 'record_id' => 1]);

        $this->resetCapture();
        $this->sync(); // nothing changed on either side

        $this->assertSame([], $this->fakeClient->updatedByIndex);
        $this->assertSame([], $this->fakeClient->appended);
    }
}
