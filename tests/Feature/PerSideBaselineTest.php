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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Zuko\SyncroSheet\Services\GoogleClient;
use Zuko\SyncroSheet\Services\SyncManager;
use Zuko\SyncroSheet\Services\TrackingDatabase;
use Zuko\SyncroSheet\Tests\Fixtures\FakeGoogleClient;
use Zuko\SyncroSheet\Tests\Fixtures\TestLedger;
use Zuko\SyncroSheet\Tests\TestCase;

/**
 * Each side is compared with its own form at the last sync: a row typed on
 * the sheet keeps its spacing / blanks, and normalisation differences between
 * the sheet and the DB never trigger a write.
 *
 * Layout (TestLedger): A amount | B note | C (blank) | D total (read-only) | E id
 */
class PerSideBaselineTest extends TestCase
{
    private const HEADER = ['amount', 'note', '', 'total', 'id'];

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

    private function sync(): int
    {
        $this->fakeClient->updatedByIndex = [];
        $this->fakeClient->appended = [];

        return app(SyncManager::class)->sync(TestLedger::class)->total_processed;
    }

    public function test_a_row_typed_on_the_sheet_only_gets_its_key(): void
    {
        // Trailing spaces: the DB stores the note trimmed, the sheet keeps them.
        $this->fakeClient->sheetValues = [self::HEADER, [42, 'typed  ', '', '=A2']];

        $this->assertSame(1, $this->sync());
        $ledger = TestLedger::sole();
        $this->assertSame('typed', $ledger->note);
        $this->assertSame([2 => [4 => $ledger->id]], $this->fakeClient->updatedByIndex);

        // The sheet as it now reads: unchanged + the key. Nothing to do.
        $this->fakeClient->sheetValues = [self::HEADER, [42, 'typed  ', '', '=A2', $ledger->id]];
        $this->assertSame(0, $this->sync());
        $this->assertSame([], $this->fakeClient->updatedByIndex);
        $this->assertSame(0, $this->sync());
    }

    public function test_a_sheet_edit_is_imported_once_without_a_write_back(): void
    {
        $ledger = TestLedger::create(['amount' => 10, 'note' => 'a']);
        $this->fakeClient->sheetValues = [self::HEADER, [10, 'a', '', 10, $ledger->id]];
        $this->sync();

        $this->fakeClient->sheetValues = [self::HEADER, [10, 'edited  ', '', 10, $ledger->id]];
        $this->assertSame(1, $this->sync());
        $this->assertSame('edited', $ledger->fresh()->note);
        $this->assertSame([], $this->fakeClient->updatedByIndex);

        // The DB form ('edited') differs from the sheet's ('edited  '): not a change.
        $this->assertSame(0, $this->sync());
        $this->assertSame([], $this->fakeClient->updatedByIndex);
    }

    public function test_a_db_edit_is_exported_once(): void
    {
        $ledger = TestLedger::create(['amount' => 10, 'note' => 'a']);
        $this->fakeClient->sheetValues = [self::HEADER, [10, 'a  ', '', 10, $ledger->id]];
        $this->sync();

        $ledger->update(['note' => 'from the app']);
        $this->assertSame(1, $this->sync());
        $this->assertSame('from the app', $this->fakeClient->updatedByIndex[2][1]);

        $this->fakeClient->sheetValues = [self::HEADER, [10, 'from the app', '', 10, $ledger->id]];
        $this->assertSame(0, $this->sync());
    }

    public function test_a_baseline_without_sheet_hash_counts_for_both_sides(): void
    {
        $ledger = TestLedger::create(['amount' => 10, 'note' => 'a']);
        $this->fakeClient->sheetValues = [self::HEADER, [10, 'a', '', 10, $ledger->id]];
        $this->sync();
        DB::connection(TrackingDatabase::connectionName())->table('sync_snapshots')->update(['sheet_hash' => null]);

        $this->assertSame(0, $this->sync());

        $this->fakeClient->sheetValues = [self::HEADER, [10, 'b', '', 10, $ledger->id]];
        $this->assertSame(1, $this->sync());
        $this->assertSame('b', $ledger->fresh()->note);
    }
}
