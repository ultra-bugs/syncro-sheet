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
use Zuko\SyncroSheet\Exceptions\ImportException;
use Zuko\SyncroSheet\Services\GoogleClient;
use Zuko\SyncroSheet\Services\SyncManager;
use Zuko\SyncroSheet\Tests\Fixtures\FakeGoogleClient;
use Zuko\SyncroSheet\Tests\Fixtures\TestProduct;
use Zuko\SyncroSheet\Tests\TestCase;

class ImporterTest extends TestCase
{
    private FakeGoogleClient $fakeClient;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake(); // keep retry/backoff jobs from running inline

        $this->fakeClient = new FakeGoogleClient();
        $this->app->instance(GoogleClient::class, $this->fakeClient);
    }

    public function test_import_full_upserts_rows_by_primary_key(): void
    {
        TestProduct::create(['id' => 1, 'name' => 'Old name', 'price' => 10]);

        $this->fakeClient->sheetValues = [
            ['id', 'name', 'price'],       // header row
            ['1', 'Updated name', '99'],   // updates the existing record
            ['2', 'Brand new', '50'],      // creates a new record
        ];

        $syncState = app(SyncManager::class)->importFull(TestProduct::class);

        $this->assertSame('import', $syncState->direction);
        $this->assertSame('completed', $syncState->status);
        $this->assertSame(2, $syncState->total_processed);

        $this->assertDatabaseHas('products', ['id' => 1, 'name' => 'Updated name', 'price' => 99]);
        $this->assertDatabaseHas('products', ['id' => 2, 'name' => 'Brand new', 'price' => 50]);

        // Upsert never deletes: exactly the two records remain.
        $this->assertSame(2, TestProduct::count());
        $this->assertDatabaseCount('sync_entries', 2);
    }

    public function test_import_partial_only_touches_requested_ids(): void
    {
        TestProduct::create(['id' => 1, 'name' => 'One', 'price' => 1]);

        $this->fakeClient->sheetValues = [
            ['id', 'name', 'price'],
            ['1', 'One edited', '11'],
            ['2', 'Two', '22'],
        ];

        $syncState = app(SyncManager::class)->importPartial(TestProduct::class, [1]);

        $this->assertSame(1, $syncState->total_processed);
        $this->assertDatabaseHas('products', ['id' => 1, 'name' => 'One edited', 'price' => 11]);
        $this->assertDatabaseMissing('products', ['id' => 2]);
    }

    public function test_empty_string_cells_become_null(): void
    {
        $this->fakeClient->sheetValues = [
            ['id', 'name', 'price'],
            ['5', '', ''],
        ];

        app(SyncManager::class)->importFull(TestProduct::class);

        $this->assertDatabaseHas('products', ['id' => 5, 'name' => null, 'price' => null]);
    }

    public function test_import_throws_when_key_column_is_missing(): void
    {
        $this->fakeClient->sheetValues = [
            ['name', 'price'], // no 'id' column
            ['x', '1'],
        ];

        $this->expectException(ImportException::class);

        app(SyncManager::class)->importFull(TestProduct::class);
    }
}
