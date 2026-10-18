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
use Zuko\SyncroSheet\Exceptions\GoogleSheetsException;
use Zuko\SyncroSheet\Models\SyncState;
use Zuko\SyncroSheet\Services\GoogleClient;
use Zuko\SyncroSheet\Services\StateManager;
use Zuko\SyncroSheet\Services\SyncManager;
use Zuko\SyncroSheet\Services\TrackingDatabase;
use Zuko\SyncroSheet\Tests\Fixtures\FakeGoogleClient;
use Zuko\SyncroSheet\Tests\Fixtures\TestLedger;
use Zuko\SyncroSheet\Tests\TestCase;

/**
 * A first run that creates many records from the sheet: a bounded number of
 * queries per record, and chunks that stay done when a later chunk fails.
 *
 * Layout (TestLedger): A amount | B note | C (blank) | D total (read-only) | E id
 */
class FirstRunAtScaleTest extends TestCase
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

    /** @return array<int, array<int, mixed>> header + $n typed rows without a key */
    private static function typedRows(int $n): array
    {
        return [self::HEADER, ...array_map(fn (int $i) => [$i, "typed $i", '', $i], range(1, $n))];
    }

    private function snapshots(): int
    {
        return DB::connection(TrackingDatabase::connectionName())->table('sync_snapshots')->count();
    }

    public function test_created_records_are_reloaded_in_one_query(): void
    {
        $this->fakeClient->sheetValues = self::typedRows(30);
        $reloads = 0;
        DB::listen(function ($query) use (&$reloads) {
            if (str_starts_with($query->sql, 'select') && str_contains($query->sql, 'from "ledgers"') && str_contains($query->sql, ' in (')) {
                $reloads++;
            }
        });

        app(SyncManager::class)->sync(TestLedger::class);

        $this->assertSame(30, TestLedger::count());
        $this->assertSame(1, $reloads, 'one reload for the chunk, not one fresh() per record');
    }

    public function test_finished_chunks_stay_done_when_a_later_chunk_fails(): void
    {
        config(['syncro-sheet.defaults.reconcile.create_chunk' => 2]);
        $this->fakeClient->sheetValues = self::typedRows(5);
        $this->fakeClient->failUpdatesFrom = 2; // the second chunk's key write-back fails

        try {
            app(SyncManager::class)->sync(TestLedger::class);
            $this->fail('the run should have failed');
        } catch (GoogleSheetsException) {
        }

        // Chunk 1 committed with its keys and baselines; chunk 2 rolled back.
        $this->assertSame(2, TestLedger::count());
        $this->assertSame(2, $this->snapshots());
        $this->assertSame([2, 3], array_keys($this->fakeClient->updatedByIndex));
        $this->assertSame('failed', SyncState::query()->latest('id')->value('status'));

        // The sheet now carries chunk 1's keys; the next run creates the rest once.
        foreach ($this->fakeClient->updatedByIndex as $row => $cells) {
            $this->fakeClient->sheetValues[$row - 1][4] = $cells[4];
        }
        $this->fakeClient->failUpdatesFrom = null;
        $state = app(SyncManager::class)->sync(TestLedger::class);

        $this->assertSame(3, $state->total_processed);
        $this->assertSame(5, TestLedger::count());
        $this->assertSame(5, $this->snapshots());
        $this->assertEqualsCanonicalizing(['typed 1', 'typed 2', 'typed 3', 'typed 4', 'typed 5'], TestLedger::pluck('note')->all());
    }

    public function test_running_runs_can_be_ended_now(): void
    {
        $state = app(StateManager::class);
        $mine = $state->initializeSync(TestLedger::class, 'full', 'append', SyncManager::DIRECTION_BIDIRECTIONAL);
        $other = $state->initializeSync('Other\\Model', 'full', 'append', SyncManager::DIRECTION_BIDIRECTIONAL);

        $this->assertSame(1, $state->failRunningRuns(TestLedger::class, 'Timed out'));

        $this->assertSame('failed', $mine->fresh()->status);
        $this->assertSame('Timed out', $mine->fresh()->error_message);
        $this->assertSame('running', $other->fresh()->status);
    }

    public function test_large_baselines_are_written_in_batches(): void
    {
        $hashes = [];
        foreach (range(1, StateManager::WRITE_BATCH * 2 + 7) as $id) {
            $hashes[$id] = ['db' => "d$id", 'sheet' => "s$id"];
        }

        app(StateManager::class)->storeSnapshots(TestLedger::class, $hashes);

        $this->assertSame(StateManager::WRITE_BATCH * 2 + 7, $this->snapshots());
    }
}
