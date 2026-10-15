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

use Zuko\SyncroSheet\Exceptions\SyncException;
use Zuko\SyncroSheet\Services\GoogleClient;
use Zuko\SyncroSheet\Services\SyncManager;
use Zuko\SyncroSheet\Tests\Fixtures\FakeGoogleClient;
use Zuko\SyncroSheet\Tests\Fixtures\TestProduct;
use Zuko\SyncroSheet\Tests\Fixtures\TestProductWithoutKey;
use Zuko\SyncroSheet\Tests\TestCase;

/**
 * The primary-key column is required for any bidirectional model (one that is
 * both SheetSyncable and SheetImportable), on both the export and import paths.
 */
class BidirectionalGuardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(GoogleClient::class, new FakeGoogleClient());
    }

    public function test_export_rejects_bidirectional_model_missing_key_column(): void
    {
        $this->expectException(SyncException::class);
        $this->expectExceptionMessage('primary-key column [id]');

        app(SyncManager::class)->fullSync(TestProductWithoutKey::class);
    }

    public function test_import_rejects_bidirectional_model_missing_key_column(): void
    {
        $this->expectException(SyncException::class);
        $this->expectExceptionMessage('primary-key column [id]');

        app(SyncManager::class)->importFull(TestProductWithoutKey::class);
    }

    public function test_properly_configured_bidirectional_model_passes_the_guard(): void
    {
        // TestProduct exposes 'id' in toSheetRow(), so neither direction throws.
        $export = app(SyncManager::class)->fullSync(TestProduct::class);
        $this->assertSame('completed', $export->status);

        $import = app(SyncManager::class)->importFull(TestProduct::class);
        $this->assertSame('completed', $import->status);
    }
}
