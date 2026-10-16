# WIP

This package is not avail on packagist yet. If you interesting, take a local clone or zip download then try it out.

# SyncroSheet

Laravel package for efficient synchronization between your models and Google Sheets with advanced state tracking and error handling.

## Features

- 🔄 Bidirectional sync between Laravel models and Google Sheets
  - **Export** (DB → Sheet) via `SheetSyncable`
  - **Import** (Sheet → DB) via `SheetImportable`, upsert-by-primary-key
  - **Two-way reconcile** (`sync()`) — detects which side changed since the last
    sync and propagates it, resolving genuine conflicts by a fixed source-of-truth
- 📦 Batch processing with memory efficiency
- 🎯 Support for both full and partial syncs
- 💾 Sophisticated state tracking and resume capability
- 🔁 Automatic retry mechanism with exponential backoff
- 📊 Detailed sync history and progress tracking
- 🚨 Comprehensive notification system
- 🔑 Smart token management for Google API

## Requirements

- PHP `>= 8.1`
- Laravel `>= 10`
- Google API accessible

## Installation

```bash
composer require zuko/syncro-sheet
```

Publish the configuration and migrations:

```bash
php artisan vendor:publish --provider="Zuko\SyncroSheet\LaravelSyncroSheetProvider"
php artisan migrate
```

## Configuration

### Google Sheets Setup

1. Create a Google Cloud Project
2. Enable Google Sheets API
3. Create credentials (OAuth 2.0 or Service Account)
4. Set up your .env file:

```env
GOOGLE_SHEETS_CLIENT_ID=your-client-id
GOOGLE_SHEETS_CLIENT_SECRET=your-client-secret
GOOGLE_SHEETS_REDIRECT_URI=your-redirect-uri
```

Or using service account:

```env
GOOGLE_DEVELOPER_KEY=your-service-account-key
GOOGLE_SERVICE_ENABLED=true
```
As a wrapped around `revolution/laravel-google-sheets`. these env vars is taken from `config/google.php`

If you already set these authorization values. You can leave env untouched.


### Package Configuration

```php
// config/syncro-sheet.php

return [
    'defaults' => [
        'batch_size' => 1000,
        'timeout' => 600,
        'retries' => 3
    ],
    // ... other configurations
];
```

## Basic Usage

### 1. Make Your Model Sync-able

```php
use Zuko\SyncroSheet\Contracts\SheetSyncable;

class Product extends Model implements SheetSyncable
{
    public function getSheetIdentifier(): string
    {
        return '1234567890-your-google-sheet-id';
    }

    public function getSheetName(): string
    {
        return 'Products';
    }

    public function toSheetRow(): array
    {
        return [
            $this->id,
            $this->name,
            $this->price,
            $this->stock,
            $this->updated_at->format('Y-m-d H:i:s')
        ];
    }

    public function getBatchSize(): ?int
    {
        return 500; // Optional, defaults to config value
    }
}
```

### 2. Run Sync Operations

```php
use Zuko\SyncroSheet\Services\SyncManager;

// Full sync
$syncManager = app(SyncManager::class);
$syncState = $syncManager->fullSync(Product::class);

// Partial sync
$syncState = $syncManager->partialSync(Product::class, [1, 2, 3]);
```
Or using facade static call
```php
use SyncroSheet;
// or
use Zuko\SyncroSheet\Facades\SyncroSheet;

// Full sync
$syncState = SyncroSheet::fullSync(Product::class);

// Partial sync
$syncState = SyncroSheet::partialSync(Product::class, [1, 2, 3]);

// Get last sync state
$lastSync = SyncroSheet::getLastSync(Product::class);
```

### 3. Artisan Commands

```bash
# Full export (DB -> Sheet) — default direction
php artisan sheet:sync Product

# Partial export
php artisan sheet:sync Product --ids=1,2,3

# Full import (Sheet -> DB)
php artisan sheet:sync Product --direction=import

# Partial import (only these primary keys)
php artisan sheet:sync Product --direction=import --ids=1,2,3
```

### 4. Import (Sheet → DB)

Make your model importable by implementing `SheetImportable`. The
`InteractsWithSheet` trait provides defaults (`getSheetKeyColumn()` returns the
model key name, `fromSheetRow()` maps headers straight to attributes), so most
models only need to declare the interface:

```php
use Zuko\SyncroSheet\Contracts\SheetSyncable;
use Zuko\SyncroSheet\Contracts\SheetImportable;
use Zuko\SyncroSheet\Traits\InteractsWithSheet;

class Product extends Model implements SheetSyncable, SheetImportable
{
    use InteractsWithSheet;

    // ...export methods (getSheetIdentifier/getSheetName/toSheetRow)...

    // Override only when the sheet layout differs from the table:
    public function getSheetKeyColumn(): string
    {
        return 'id'; // sheet header holding the primary key
    }

    public function fromSheetRow(array $row): array
    {
        return [
            'name'  => $row['Product Name'] ?? null,
            'price' => $row['Price'] ?? null,
            'stock' => $row['In Stock'] ?? 0,
        ];
    }
}
```

```php
use Zuko\SyncroSheet\Facades\SyncroSheet;

// Pull the whole sheet back into the DB (update existing by PK, create new)
$syncState = SyncroSheet::importFull(Product::class);

// Import only specific primary keys
$syncState = SyncroSheet::importPartial(Product::class, [1, 2, 3]);
```

> **Primary key is required.** A bidirectional model (one implementing both
> `SheetSyncable` and `SheetImportable`) must expose its key column in the
> exported row — the key has to round-trip through the sheet so import can match
> rows back. If `getSheetKeyColumn()` is absent from `toSheetRow()` /
> `defaultSheetHeaders()`, both `fullSync()` and `importFull()` throw a
> `SyncException` up front.
>
> **Matching & semantics:** rows are matched to records by the primary-key
> column (`getSheetKeyColumn()`). Writes are **upsert** — matched rows update,
> new rows create, nothing is deleted. Set
> `syncro-sheet.defaults.import.use_batch_upsert = true` to use `Model::upsert()`
> (one query per chunk, skips model events/mutators) for large trusted imports.
> Models with a non-numeric (UUID/string) primary key are not supported yet
> (the `sync_entries.record_id` column is a `bigInteger`).

### 5. Bidirectional (two-way reconcile)

For a model that implements **both** `SheetSyncable` and `SheetImportable`, a
single `sync()` call reconciles both directions:

```php
use Zuko\SyncroSheet\Facades\SyncroSheet;

$syncState = SyncroSheet::sync(Product::class); // direction = 'bidirectional'
```

```bash
php artisan sheet:sync Product --direction=both
```

How it decides, per record (matched by primary key):

- A content-hash **baseline** of each record's last-synced state is kept in the
  `sync_snapshots` table. The reconciler compares the current DB-side and
  sheet-side hashes against that baseline.
- Only the DB changed → the sheet row is updated in place; only the sheet
  changed → the record is upserted into the DB.
- **Both changed (a real conflict)** → the **source of truth** wins. Set it per
  model with `getSyncSourceOfTruth(): string` (`'db'` or `'sheet'`), falling back
  to `config('syncro-sheet.defaults.source_of_truth')`.
- A record present on only one side is propagated to the other. **Nothing is
  deleted** — a row missing from the sheet is re-exported, a record missing from
  the DB is re-created.

> **Volatile columns.** Change detection hashes the sheet row, so volatile fields
> (e.g. `updated_at`) would make the DB look "changed" every run. They are
> excluded via `config('syncro-sheet.defaults.reconcile.hash_ignore')` (default
> `['updated_at']`); a model can override with `syncHashIgnore(): array`.
>
> **First run** (no baseline yet): records present on both sides that differ are
> treated as conflicts and resolved by the source of truth; the baseline is
> seeded afterwards.

## Advanced Usage

### 1. Custom Data Transformation

```php
use Zuko\SyncroSheet\Services\DataTransformer;

class ProductTransformer extends DataTransformer
{
    protected function transformRecord(SheetSyncable $record): array
    {
        return [
            'ID' => $record->id,
            'Product Name' => $record->name,
            'Price' => number_format($record->price, 2),
            'In Stock' => $record->stock > 0 ? 'Yes' : 'No',
            'Last Updated' => $record->updated_at->format('Y-m-d H:i:s')
        ];
    }
}
```

### 2. Event Listeners

```php
use Zuko\SyncroSheet\Events\SyncEvent;

Event::listen(SyncEvent::SYNC_STARTED, function ($syncState) {
    Log::info("Sync started for {$syncState->model_class}");
});

Event::listen(SyncEvent::SYNC_COMPLETED, function ($syncState) {
    Log::info("Sync completed: {$syncState->total_processed} records");
});
```

### 3. Custom Notifications

```php
use Zuko\SyncroSheet\Notifications\BaseNotification;

class CustomSyncNotification extends BaseNotification
{
    protected function getMailMessage(): MailMessage
    {
        return (new MailMessage)
            ->subject('Custom Sync Notification')
            ->line('Your custom notification logic here');
    }

    protected function getSlackMessage(): SlackMessage
    {
        return (new SlackMessage)
            ->content('Custom Slack notification');
    }
}
```

### 4. State Management

```php
use Zuko\SyncroSheet\Services\StateManager;

$stateManager = app(StateManager::class);

// Get last successful sync
$lastSync = $stateManager->getLastSuccessfulSync(Product::class);

// Get sync history
$syncHistory = \Zuko\SyncroSheet\Models\SyncState::where('model_class', Product::class)
    ->with('entries')
    ->latest()
    ->get();
```

### 5. Error Handling

```php
use Zuko\SyncroSheet\Services\ErrorHandler;

try {
    $syncManager->fullSync(Product::class);
} catch (GoogleSheetsException $e) {
    // Handle Google Sheets specific errors
} catch (SyncException $e) {
    // Handle general sync errors
}
```

## Best Practices

1. **Batch Size**: Adjust based on your model's complexity and memory constraints
```php
public function getBatchSize(): int
{
    return $this->hasMedia() ? 100 : 1000;
}
```

2. **Rate Limiting**: Configure based on your Google Sheets API quotas
```php
// config/syncro-sheet.php
'sheets' => [
    'rate_limit' => [
        'max_requests' => 100,
        'per_seconds' => 60
    ]
]
```

3. **Error Handling**: Implement custom notification channels
```php
use Zuko\SyncroSheet\Notifications\SyncFailedNotification;

class SlackSyncNotifier extends Notification
{
    public function toSlack($notifiable)
    {
        // Custom Slack notification logic
    }
}
```

## Contributing

Please see [CONTRIBUTING.md](CONTRIBUTING.md) for details.

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
