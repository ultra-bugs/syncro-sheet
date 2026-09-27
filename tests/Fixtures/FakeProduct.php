<?php

namespace Zuko\SyncroSheet\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Zuko\SyncroSheet\Contracts\SheetSyncable;

class FakeProduct extends Model implements SheetSyncable
{
    protected $table = 'fake_products';

    protected $guarded = [];

    public function getSheetIdentifier(): string
    {
        return 'test-spreadsheet-id';
    }

    public function getSheetName(): string
    {
        return 'Products';
    }

    public function toSheetRow(): array
    {
        return [
            'Name' => $this->name,
            'Price' => $this->price,
            'SKU' => $this->sku,
        ];
    }

    public function getIdColumnOnSheet(): ?string
    {
        return 'DB_ID';
    }

    public function fromSheetRow(array $row): array
    {
        return [
            'name' => $row['Name'] ?? null,
            'price' => $row['Price'] ?? null,
            'sku' => $row['SKU'] ?? null,
        ];
    }

    public function getSyncDirection(): string
    {
        return 'bidirectional';
    }

    public function defaultSheetHeaders(): array
    {
        return ['Name', 'Price', 'SKU', 'DB_ID'];
    }
}
