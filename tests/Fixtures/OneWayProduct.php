<?php

namespace Zuko\SyncroSheet\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Zuko\SyncroSheet\Contracts\SheetSyncable;

class OneWayProduct extends Model implements SheetSyncable
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
}
