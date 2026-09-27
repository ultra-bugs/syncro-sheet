<?php

namespace Zuko\SyncroSheet\Tests\Unit;

use Mockery;
use Zuko\SyncroSheet\Services\GoogleClient;
use Zuko\SyncroSheet\Services\SheetReader;
use Zuko\SyncroSheet\Services\SyncLogger;
use Zuko\SyncroSheet\Tests\Fixtures\FakeProduct;
use Zuko\SyncroSheet\Tests\TestCase;

class SheetReaderTest extends TestCase
{
    private SheetReader $reader;

    private GoogleClient $googleClient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->googleClient = Mockery::mock(GoogleClient::class);
        $logger = Mockery::mock(SyncLogger::class)->shouldIgnoreMissing();
        $this->reader = new SheetReader($this->googleClient, $logger);
    }

    public function test_read_sheet_data_returns_headers_and_rows(): void
    {
        $model = new FakeProduct;

        $this->googleClient->shouldReceive('readSheet')
            ->once()
            ->with('test-spreadsheet-id', 'Products')
            ->andReturn([
                ['Name', 'Price', 'SKU', 'DB_ID'],
                ['Widget', '9.99', 'W001', '1'],
                ['Gadget', '19.99', 'G001', ''],
            ]);

        $result = $this->reader->readSheetData($model);

        $this->assertEquals(['Name', 'Price', 'SKU', 'DB_ID'], $result['headers']);
        $this->assertCount(2, $result['rows']);
        $this->assertEquals('Widget', $result['rows'][2]['Name']);
        $this->assertEquals('Gadget', $result['rows'][3]['Name']);
    }

    public function test_read_sheet_data_handles_empty_sheet(): void
    {
        $model = new FakeProduct;

        $this->googleClient->shouldReceive('readSheet')
            ->once()
            ->andReturn([]);

        $result = $this->reader->readSheetData($model);

        $this->assertEmpty($result['headers']);
        $this->assertEmpty($result['rows']);
    }

    public function test_partition_by_id_column_separates_new_and_existing(): void
    {
        $model = new FakeProduct;

        $this->googleClient->shouldReceive('readSheet')
            ->once()
            ->andReturn([
                ['Name', 'Price', 'SKU', 'DB_ID'],
                ['Widget', '9.99', 'W001', '42'],
                ['Gadget', '19.99', 'G001', ''],
                ['Thingamajig', '29.99', 'T001', '99'],
            ]);

        $result = $this->reader->partitionByIdColumn($model);

        $this->assertCount(2, $result['existing']);
        $this->assertCount(1, $result['new']);
        $this->assertEquals('42', $result['existing'][2]['DB_ID']);
        $this->assertEquals('', $result['new'][3]['DB_ID']);
    }

    public function test_get_id_column_name_returns_model_value(): void
    {
        $model = new FakeProduct;
        $this->assertEquals('DB_ID', $this->reader->getIdColumnName($model));
    }

    public function test_extract_db_ids_maps_row_numbers_to_ids(): void
    {
        $model = new FakeProduct;
        $rows = [
            2 => ['Name' => 'Widget', 'DB_ID' => '42'],
            3 => ['Name' => 'Gadget', 'DB_ID' => ''],
            4 => ['Name' => 'Thing', 'DB_ID' => '99'],
        ];

        $ids = $this->reader->extractDbIds($model, $rows);

        $this->assertEquals([2 => '42', 4 => '99'], $ids);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
