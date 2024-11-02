# SyncroSheet

Laravel package for efficient synchronization between your models and Google Sheets with advanced state tracking and error handling.

## Features

- 🔄 Sync from Laravel models to Google Sheets. Bidirectional sync is planned to implement
- 📦 Batch processing with memory efficiency
- 🎯 Support for both full and partial syncs
- 💾 Sophisticated state tracking and resume capability
- 🔁 Automatic retry mechanism with exponential backoff
- 📊 Detailed sync history and progress tracking
- 🚨 Comprehensive notification system
- 🔑 Smart token management for Google API

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

### Package Configuration

```php
// As a wrapped around `revolution/laravel-google-sheets`. I'm respecting the values in `config/google.php*
// If you already set these authorization values. You can leave blank in this file
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
# Full sync
php artisan sheet:sync Product

# Partial sync
php artisan sheet:sync Product --ids=1,2,3
```

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
