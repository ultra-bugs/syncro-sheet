<?php

namespace Zuko\SyncroSheet\Tests\Unit;

use Zuko\SyncroSheet\Services\ContentHasher;
use Zuko\SyncroSheet\Tests\TestCase;

class ContentHasherTest extends TestCase
{
    private ContentHasher $hasher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hasher = new ContentHasher;
    }

    public function test_same_input_produces_same_hash(): void
    {
        $row = ['Name' => 'Widget', 'Price' => '9.99', 'SKU' => 'W001'];

        $hash1 = $this->hasher->hash($row);
        $hash2 = $this->hasher->hash($row);

        $this->assertSame($hash1, $hash2);
    }

    public function test_different_input_produces_different_hash(): void
    {
        $row1 = ['Name' => 'Widget', 'Price' => '9.99'];
        $row2 = ['Name' => 'Gadget', 'Price' => '19.99'];

        $this->assertNotSame($this->hasher->hash($row1), $this->hasher->hash($row2));
    }

    public function test_hash_is_32_char_md5(): void
    {
        $hash = $this->hasher->hash(['Name' => 'Widget']);

        $this->assertSame(32, strlen($hash));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $hash);
    }

    public function test_normalizes_numeric_strings(): void
    {
        $row1 = ['Price' => '9.99'];
        $row2 = ['Price' => 9.99];

        $this->assertSame($this->hasher->hash($row1), $this->hasher->hash($row2));
    }

    public function test_normalizes_null_and_empty_string(): void
    {
        $row1 = ['Name' => null];
        $row2 = ['Name' => ''];

        $this->assertSame($this->hasher->hash($row1), $this->hasher->hash($row2));
    }

    public function test_trims_whitespace(): void
    {
        $row1 = ['Name' => 'Widget'];
        $row2 = ['Name' => '  Widget  '];

        $this->assertSame($this->hasher->hash($row1), $this->hasher->hash($row2));
    }

    public function test_has_changed_returns_true_for_null_stored(): void
    {
        $this->assertTrue($this->hasher->hasChanged('abc123', null));
    }

    public function test_has_changed_returns_true_for_different_hashes(): void
    {
        $this->assertTrue($this->hasher->hasChanged('abc123', 'def456'));
    }

    public function test_has_changed_returns_false_for_same_hashes(): void
    {
        $this->assertFalse($this->hasher->hasChanged('abc123', 'abc123'));
    }

    public function test_stored_hash_returns_null_when_no_entry(): void
    {
        $this->assertNull($this->hasher->getStoredHash('App\\Models\\Fake', 999));
    }

    public function test_stored_hashes_returns_empty_for_no_records(): void
    {
        $this->assertEmpty($this->hasher->getStoredHashes('App\\Models\\Fake', [1, 2, 3]));
    }

    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();
        $this->loadMigrationsFrom(__DIR__.'/../Fixtures');
    }
}
