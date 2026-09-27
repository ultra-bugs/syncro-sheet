<?php

namespace Zuko\SyncroSheet\Tests\Unit;

use Mockery;
use Zuko\SyncroSheet\Services\RecordMatcher;
use Zuko\SyncroSheet\Services\SyncLogger;
use Zuko\SyncroSheet\Tests\Fixtures\FakeProduct;
use Zuko\SyncroSheet\Tests\TestCase;

class RecordMatcherTest extends TestCase
{
    private RecordMatcher $matcher;

    protected function setUp(): void
    {
        parent::setUp();
        $logger = Mockery::mock(SyncLogger::class)->shouldIgnoreMissing();
        $this->matcher = new RecordMatcher($logger);
    }

    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();
        $this->loadMigrationsFrom(__DIR__.'/../Fixtures');
    }

    public function test_match_by_ids_finds_existing_records(): void
    {
        $product1 = FakeProduct::create(['name' => 'Widget', 'price' => 9.99, 'sku' => 'W001']);
        $product2 = FakeProduct::create(['name' => 'Gadget', 'price' => 19.99, 'sku' => 'G001']);

        $sheetRows = [
            2 => ['Name' => 'Widget', 'Price' => '9.99', 'DB_ID' => (string) $product1->id],
            3 => ['Name' => 'New Product', 'Price' => '5.00', 'DB_ID' => '9999'],
        ];

        $dbIdMap = [
            2 => $product1->id,
            3 => 9999,
        ];

        $result = $this->matcher->matchByIds(FakeProduct::class, $sheetRows, $dbIdMap);

        $this->assertCount(1, $result['matched']);
        $this->assertCount(1, $result['unmatched']);
        $this->assertEquals($product1->id, $result['matched'][2]['db_id']);
    }

    public function test_match_by_ids_returns_all_unmatched_when_empty_map(): void
    {
        $sheetRows = [
            2 => ['Name' => 'Widget'],
            3 => ['Name' => 'Gadget'],
        ];

        $result = $this->matcher->matchByIds(FakeProduct::class, $sheetRows, []);

        $this->assertEmpty($result['matched']);
        $this->assertCount(2, $result['unmatched']);
    }

    public function test_detect_changes_finds_modified_records(): void
    {
        $product = FakeProduct::create(['name' => 'Widget', 'price' => 9.99, 'sku' => 'W001']);
        $model = new FakeProduct;

        $matchedRows = [
            2 => [
                'sheet_data' => ['Name' => 'Updated Widget', 'Price' => '15.99', 'SKU' => 'W001'],
                'db_record' => $product,
                'db_id' => $product->id,
            ],
        ];

        $changed = $this->matcher->detectChanges($model, $matchedRows);

        $this->assertCount(1, $changed);
        $this->assertEquals('Updated Widget', $changed[2]['new_attributes']['name']);
        $this->assertEquals('15.99', $changed[2]['new_attributes']['price']);
    }

    public function test_detect_changes_skips_unchanged_records(): void
    {
        $product = FakeProduct::create(['name' => 'Widget', 'price' => 9.99, 'sku' => 'W001']);
        $model = new FakeProduct;

        $matchedRows = [
            2 => [
                'sheet_data' => ['Name' => 'Widget', 'Price' => '9.99', 'SKU' => 'W001'],
                'db_record' => $product,
                'db_id' => $product->id,
            ],
        ];

        $changed = $this->matcher->detectChanges($model, $matchedRows);

        $this->assertEmpty($changed);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
