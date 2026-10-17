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

use Zuko\SyncroSheet\Services\GoogleClient;
use Zuko\SyncroSheet\Services\SyncManager;
use Zuko\SyncroSheet\Tests\Fixtures\FakeGoogleClient;
use Zuko\SyncroSheet\Tests\Fixtures\TestProduct;
use Zuko\SyncroSheet\Tests\TestCase;

/**
 * Regression coverage for the export engine after the BatchProcessor -> Exporter
 * rename, so the original DB -> Sheet path keeps working.
 */
class ExporterTest extends TestCase
{
    private FakeGoogleClient $fakeClient;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeClient = new FakeGoogleClient();
        $this->app->instance(GoogleClient::class, $this->fakeClient);
    }

    public function test_full_sync_exports_records_to_the_sheet(): void
    {
        TestProduct::create(['id' => 1, 'name' => 'A', 'price' => 10]);
        TestProduct::create(['id' => 2, 'name' => 'B', 'price' => 20]);

        $this->fakeClient->sheetValues = []; // empty sheet -> headers get written

        $syncState = app(SyncManager::class)->fullSync(TestProduct::class);

        $this->assertSame('export', $syncState->direction);
        $this->assertSame('completed', $syncState->status);
        $this->assertSame(2, $syncState->total_processed);

        // Headers derived from toSheetRow() keys.
        $this->assertSame(['id', 'name', 'price'], $this->fakeClient->headersSet);

        // Both records appended, keyed by header.
        $this->assertCount(2, $this->fakeClient->appended);
        $this->assertSame('A', $this->fakeClient->appended[0]['name']);
        $this->assertSame('B', $this->fakeClient->appended[1]['name']);

        $this->assertDatabaseCount('sync_entries', 2);
    }
}
