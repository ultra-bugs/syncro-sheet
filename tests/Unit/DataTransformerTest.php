<?php

namespace Zuko\SyncroSheet\Tests\Unit;

use Zuko\SyncroSheet\Services\DataTransformer;
use Zuko\SyncroSheet\Tests\Fixtures\FakeProduct;
use Zuko\SyncroSheet\Tests\TestCase;

class DataTransformerTest extends TestCase
{
    private DataTransformer $transformer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->transformer = new DataTransformer;
    }

    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();
        $this->loadMigrationsFrom(__DIR__.'/../Fixtures');
    }

    public function test_transform_batch_converts_models_to_rows(): void
    {
        $products = collect([
            FakeProduct::create(['name' => 'Widget', 'price' => 9.99, 'sku' => 'W001']),
            FakeProduct::create(['name' => 'Gadget', 'price' => 19.99, 'sku' => 'G001']),
        ]);

        $rows = $this->transformer->transformBatch($products);

        $this->assertCount(2, $rows);
        $this->assertEquals('Widget', $rows[0]['Name']);
        $this->assertEquals('9.99', $rows[0]['Price']);
        $this->assertEquals('Gadget', $rows[1]['Name']);
    }

    public function test_transform_batch_handles_empty_collection(): void
    {
        $rows = $this->transformer->transformBatch(collect());

        $this->assertEmpty($rows);
    }
}
