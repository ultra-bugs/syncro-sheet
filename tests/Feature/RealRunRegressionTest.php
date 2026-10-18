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
use Revolution\Google\Sheets\SheetsClient;
use Zuko\SyncroSheet\Models\SyncState;
use Zuko\SyncroSheet\Services\DataTransformer;
use Zuko\SyncroSheet\Services\GoogleClient;
use Zuko\SyncroSheet\Services\SyncLogger;
use Zuko\SyncroSheet\Services\SyncManager;
use Zuko\SyncroSheet\Services\TokenManager;
use Zuko\SyncroSheet\Tests\Fixtures\FakeGoogleClient;
use Zuko\SyncroSheet\Tests\Fixtures\TestLedger;
use Zuko\SyncroSheet\Tests\TestCase;

/**
 * Regressions found by the first real run (live Google Sheets API +
 * PostgreSQL, see docs/agent-plans/20261018_real-run-fixes.md). The PostgreSQL
 * key-sequence sync is exercised by that real run only (SQLite here).
 */
class RealRunRegressionTest extends TestCase
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

    private function sync(): SyncState
    {
        return app(SyncManager::class)->sync(TestLedger::class);
    }

    private function ledger(int $id, float $amount, string $note = ''): TestLedger
    {
        $ledger = TestLedger::create(['amount' => $amount, 'note' => $note]);
        $ledger->forceFill(['id' => $id])->save();

        return $ledger;
    }

    private function resetCapture(): void
    {
        $this->fakeClient->appended = [];
        $this->fakeClient->appendedAfter = [];
        $this->fakeClient->updatedByIndex = [];
    }

    public function test_positional_rows_are_exported_with_their_values(): void
    {
        $this->ledger(1, 10.5, 'first');
        $this->fakeClient->sheetValues = [self::HEADER];

        $this->sync();

        $this->assertSame([[10.5, 'first', '', 10.5, 1]], $this->fakeClient->appended);
    }

    public function test_positional_db_change_is_detected_per_record(): void
    {
        $this->ledger(1, 10, 'a');
        $this->ledger(2, 20, 'b');
        $this->fakeClient->sheetValues = [self::HEADER, [10, 'a', '', 10, 1], [20, 'b', '', 20, 2]];
        $this->sync();
        $this->resetCapture();

        TestLedger::find(2)->update(['note' => 'b2']);
        $this->sync();

        $this->assertSame([3], array_keys($this->fakeClient->updatedByIndex));
        $this->assertContains('b2', $this->fakeClient->updatedByIndex[3]);
    }

    public function test_appended_row_follows_the_sheet_header_positions_after_the_last_row(): void
    {
        $this->ledger(7, 1);
        // Sheet column order differs from the model and has a sheet-only column.
        $this->fakeClient->sheetValues = [['id', 'extra', 'note', 'amount', 'total'], [99, 'x', 'other', 5, 5]];

        $this->sync();

        $this->assertSame([[7, '', '', 1.0, 1.0]], $this->fakeClient->appended);
        $this->assertSame([2], $this->fakeClient->appendedAfter);
    }

    public function test_numbers_stay_numbers_and_compare_by_value(): void
    {
        $this->ledger(1, 10); // decimal:2 cast -> "10.00" in the DB
        $this->fakeClient->sheetValues = [self::HEADER, [10, '', '', 10, 1]];

        $this->sync();

        // First run, both sides equal: baseline seeded, nothing written.
        $this->assertSame([], $this->fakeClient->appended);
        $this->assertSame([], $this->fakeClient->updatedByIndex);
        $this->assertDatabaseHas('sync_snapshots', ['model_class' => TestLedger::class, 'record_id' => 1]);

        $hash = app(DataTransformer::class);
        $this->assertSame(
            $hash->canonicalHash(['a' => '10.00'], ['a']),
            $hash->canonicalHash(['a' => 10], ['a'])
        );
    }

    public function test_sheet_only_row_keeps_its_guarded_primary_key_without_duplicating(): void
    {
        $this->fakeClient->sheetValues = [self::HEADER, [42, 'from sheet', '', 42, 50]];

        $this->sync();
        $this->sync();

        $this->assertSame(1, TestLedger::count());
        $this->assertSame('from sheet', TestLedger::find(50)?->note);
    }

    public function test_soft_deleted_record_is_left_alone(): void
    {
        $this->ledger(3, 30, 'gone')->delete();
        $this->fakeClient->sheetValues = [self::HEADER, [30, 'gone', '', 30, 3]];

        $this->sync();

        $this->assertSame(1, TestLedger::withTrashed()->count());
        $this->assertTrue(TestLedger::withTrashed()->find(3)->trashed());
        $this->assertSame([], $this->fakeClient->appended);
        $this->assertSame([], $this->fakeClient->updatedByIndex);
    }

    public function test_from_sheet_row_runs_on_the_existing_record(): void
    {
        $ledger = $this->ledger(1, 10, 'a');
        $ledger->update(['meta' => ['keep' => 'me']]);
        $this->fakeClient->sheetValues = [self::HEADER, [10, 'a', '', 10, 1]];
        $this->sync();

        $this->fakeClient->sheetValues = [self::HEADER, [10, 'edited', '', 10, 1]];
        $this->sync();

        $meta = TestLedger::find(1)->meta;
        $this->assertSame('edited', TestLedger::find(1)->note);
        $this->assertSame('me', $meta['keep'] ?? null);
        $this->assertTrue($meta['note_from_sheet'] ?? false);
    }

    public function test_update_writes_only_writable_mapped_cells(): void
    {
        $this->ledger(1, 10, 'a');
        $this->fakeClient->sheetValues = [self::HEADER, [10, 'a', 'sheet-only', '=A2', 1]];
        $this->sync();
        $this->resetCapture();

        TestLedger::find(1)->update(['amount' => 11]);
        $this->sync();

        // amount (A), note (B), id (E): never the blank column C or read-only total (D).
        $this->assertSame([0, 1, 4], array_keys($this->fakeClient->updatedByIndex[2]));
    }

    public function test_empty_sheet_gets_the_header_row_before_rows(): void
    {
        $this->ledger(1, 10);
        $this->fakeClient->sheetValues = [];

        $this->sync();

        $this->assertSame(self::HEADER, $this->fakeClient->headersSet);
        $this->assertSame([1], $this->fakeClient->appendedAfter);
    }

    public function test_export_maps_positional_rows_onto_a_trimmed_header_row(): void
    {
        $this->ledger(1, 10, 'a');
        // Google trims trailing blank header cells, so the sheet header can be
        // shorter than the positional row; the extra cells must not crash.
        $this->fakeClient->sheetValues = [['amount', 'note', '', 'total', 'id']];

        app(SyncManager::class)->fullSync(TestLedger::class);

        $this->assertSame([[10.0, 'a', '', 10.0, 1]], $this->fakeClient->appended);
    }

    public function test_import_keeps_the_guarded_primary_key(): void
    {
        $this->fakeClient->sheetValues = [self::HEADER, [5, 'imported', '', 5, 77]];

        app(SyncManager::class)->importFull(TestLedger::class);

        $this->assertSame('imported', TestLedger::find(77)?->note);
    }

    public function test_service_account_file_accepts_an_absolute_path(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'sa');
        file_put_contents($file, json_encode([
            'type' => 'service_account', 'client_email' => 'x@example.iam.gserviceaccount.com',
            'client_id' => '1', 'private_key' => 'unused', 'private_key_id' => 'k',
        ]));
        config(['google.service.file' => $file]);

        $client = new GoogleClient(app(TokenManager::class), app(SyncLogger::class));
        $setup = new \ReflectionMethod($client, 'setupServiceAccount');
        $google = new \Google\Client();
        $setup->invoke($client, $google);

        @unlink($file);
        $this->assertSame('1', $google->getClientId()); // the file was found and loaded
    }

    public function test_revolution_google_config_is_merged(): void
    {
        $this->assertIsArray(config('google.service'));
    }

    public function test_sheet_names_are_quoted_so_a1_like_names_address_the_tab(): void
    {
        $client = new GoogleClient(app(TokenManager::class), app(SyncLogger::class));
        (new \ReflectionProperty($client, 'sheetsClient'))->setValue($client, new SheetsClient);
        $scoped = new \ReflectionMethod($client, 'scoped');

        $this->assertSame("'Oct25'", $scoped->invoke($client, 'id', 'Oct25')->ranges());
        $this->assertSame("'Bob''s'!A5", $scoped->invoke($client, 'id', "Bob's", 'A5')->ranges());
        $this->assertSame('AA', GoogleClient::columnLetter(26));
    }
}
