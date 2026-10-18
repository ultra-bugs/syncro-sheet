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
use Zuko\SyncroSheet\Services\GoogleClient;
use Zuko\SyncroSheet\Services\SyncManager;
use Zuko\SyncroSheet\Tests\Fixtures\FakeGoogleClient;
use Zuko\SyncroSheet\Tests\Fixtures\TestHookedLedger;
use Zuko\SyncroSheet\Tests\TestCase;

/**
 * Optional model hooks consulted through InteractsWithSheet:
 * shouldImportSheetRow() (import gate) and sheetTransformer() (codec).
 */
class ModelHooksTest extends TestCase
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

    private function sync(): void
    {
        app(SyncManager::class)->sync(TestHookedLedger::class);
    }

    public function test_appended_row_is_written_by_the_model_transformer(): void
    {
        TestHookedLedger::create(['amount' => 10, 'note' => 'coffee']);
        $this->fakeClient->sheetValues = [self::HEADER];

        $this->sync();

        $this->assertSame('COFFEE', $this->fakeClient->appended[0][1]);
    }

    public function test_hash_uses_the_same_transformer_so_runs_stay_stable(): void
    {
        $ledger = TestHookedLedger::create(['amount' => 10, 'note' => 'coffee']);
        $this->fakeClient->sheetValues = [self::HEADER, [10, 'COFFEE', '', 10, $ledger->id]];

        $this->sync();
        $this->sync();

        $this->assertSame([], $this->fakeClient->appended);
        $this->assertSame([], $this->fakeClient->updatedByIndex);
    }

    public function test_in_place_update_and_import_go_through_the_transformer(): void
    {
        $ledger = TestHookedLedger::create(['amount' => 10, 'note' => 'coffee']);
        $this->fakeClient->sheetValues = [self::HEADER, [10, 'COFFEE', '', 10, $ledger->id]];
        $this->sync();

        $ledger->update(['note' => 'tea']);
        $this->sync();
        $this->assertSame('TEA', $this->fakeClient->updatedByIndex[2][1]);

        $this->fakeClient->sheetValues = [self::HEADER, [10, 'MILK', '', 10, $ledger->id]];
        $this->sync();
        $this->assertSame('milk', $ledger->fresh()->note);
    }

    public function test_keyless_rows_pass_the_model_import_gate(): void
    {
        $this->fakeClient->sheetValues = [self::HEADER, [5, '# just a comment', '', 5], [7, 'LUNCH', '', 7]];

        $this->sync();

        $ledger = TestHookedLedger::sole();
        $this->assertSame('lunch', $ledger->note);
        $this->assertSame([3], array_keys($this->fakeClient->updatedByIndex));
        $this->assertSame('LUNCH', $this->fakeClient->updatedByIndex[3][1], 'write-back uses the transformer');
        $this->assertSame($ledger->id, $this->fakeClient->updatedByIndex[3][4]);
    }

    public function test_export_uses_the_model_transformer(): void
    {
        TestHookedLedger::create(['amount' => 10, 'note' => 'coffee']);
        $this->fakeClient->sheetValues = [self::HEADER];

        app(SyncManager::class)->fullSync(TestHookedLedger::class);

        $this->assertSame('COFFEE', $this->fakeClient->appended[0][1]);
    }
}
