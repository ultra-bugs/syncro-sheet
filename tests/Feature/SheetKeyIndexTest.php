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

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Zuko\SyncroSheet\Exceptions\ImportException;
use Zuko\SyncroSheet\Services\GoogleClient;
use Zuko\SyncroSheet\Services\Reconciler;
use Zuko\SyncroSheet\Services\SyncManager;
use Zuko\SyncroSheet\Tests\Fixtures\FakeGoogleClient;
use Zuko\SyncroSheet\Tests\Fixtures\TestLedger;
use Zuko\SyncroSheet\Tests\TestCase;

/**
 * Key located by getSheetKeyColumnIndex() (column E of the ledger fixture),
 * and rows typed on the sheet without a key: created, key written back.
 */
class SheetKeyIndexTest extends TestCase
{
    /** Like a real sheet: the key column has no header. */
    private const HEADER = ['amount', 'note', '', 'total'];

    private FakeGoogleClient $fakeClient;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        Schema::create('ledgers', function (Blueprint $table) {
            $table->id();
            $table->decimal('amount', 15, 2)->nullable();
            $table->string('note')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        $this->fakeClient = new FakeGoogleClient();
        $this->app->instance(GoogleClient::class, $this->fakeClient);
    }

    private function sync(): void
    {
        app(SyncManager::class)->sync(TestLedger::class);
    }

    public function test_key_is_read_by_position_when_its_header_is_blank(): void
    {
        $ledger = TestLedger::create(['amount' => 10, 'note' => 'a']);
        $this->fakeClient->sheetValues = [self::HEADER, [10, 'a', '', 10, $ledger->id]];

        $this->sync();

        $this->assertSame(1, TestLedger::count());
        $this->assertSame([], $this->fakeClient->appended);
        $this->assertSame([], $this->fakeClient->updatedByIndex);
    }

    public function test_row_without_a_key_is_created_and_gets_its_key_written_back(): void
    {
        $this->fakeClient->sheetValues = [self::HEADER, [42, 'typed on the sheet', '', 42]];

        $this->sync();

        $ledger = TestLedger::sole();
        $this->assertSame('typed on the sheet', $ledger->note);
        $this->assertSame($ledger->id, $this->fakeClient->updatedByIndex[2][4] ?? null);
        $this->assertArrayNotHasKey(3, $this->fakeClient->updatedByIndex[2], 'read-only total is not overwritten');

        // The sheet now carries the key: the next run matches, nothing more is created.
        $this->fakeClient->sheetValues = [self::HEADER, [42, 'typed on the sheet', '', 42, $ledger->id]];
        $this->fakeClient->updatedByIndex = [];
        $this->sync();

        $this->assertSame(1, TestLedger::count());
        $this->assertSame([], $this->fakeClient->updatedByIndex);
    }

    public function test_rows_that_are_not_records_are_left_alone(): void
    {
        $this->fakeClient->sheetValues = [self::HEADER, [null, '18/10'], [], ['', '', '', '']];

        $this->sync();

        $this->assertSame(0, TestLedger::count());
        $this->assertSame([], $this->fakeClient->updatedByIndex);
    }

    public function test_row_that_moved_before_the_write_is_not_given_a_key(): void
    {
        $this->fakeClient->sheetValues = [self::HEADER, [42, 'typed', '', 42]];
        $reconciler = app(Reconciler::class);
        $plan = $reconciler->decide(TestLedger::class);

        // Someone inserts a row above before the plan is applied.
        $this->fakeClient->sheetValues = [self::HEADER, [7, 'inserted', '', 7], [42, 'typed', '', 42]];
        $reconciler->applyChunk(TestLedger::class, $plan['decisions']);

        $this->assertSame(0, TestLedger::count());
        $this->assertSame([], $this->fakeClient->updatedByIndex);
    }

    public function test_failed_key_write_back_leaves_no_record(): void
    {
        $this->fakeClient->sheetValues = [self::HEADER, [42, 'typed', '', 42]];
        $this->fakeClient->failUpdates = true;

        try {
            $this->sync();
        } catch (\Throwable) {
            // the run fails; what matters is that no orphan record remains
        }

        $this->assertSame(0, TestLedger::count());
    }

    public function test_import_creates_keyless_rows_and_writes_the_key_cell(): void
    {
        $this->fakeClient->sheetValues = [self::HEADER, [5, 'imported', '', 5]];

        app(SyncManager::class)->importFull(TestLedger::class);

        $ledger = TestLedger::sole();
        $this->assertSame([2 => [4 => $ledger->id]], $this->fakeClient->updatedByIndex);
    }

    public function test_key_position_holding_another_header_is_rejected(): void
    {
        $this->fakeClient->sheetValues = [['amount', 'note', '', 'total', 'not-the-key'], [5, 'x', '', 5, 1]];

        $this->expectException(ImportException::class);

        app(SyncManager::class)->importFull(TestLedger::class);
    }
}
