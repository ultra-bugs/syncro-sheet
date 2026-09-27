# SyncroSheet

**Laravel Eloquent ↔ Google Sheets synchronization package**

## Package Overview

`zuko/syncro-sheet` provides bidirectional sync between Laravel Eloquent models and Google Sheets.

- **Namespace**: `Zuko\SyncroSheet\`
- **PHP**: `^8.1`
- **Laravel**: `>=10.0`
- **Key dependency**: `revolution/laravel-google-sheets` (Google Sheets API wrapper)

## Architecture

```
src/
├── Contracts/SheetSyncable.php      # Interface for syncable models
├── Console/Commands/SheetSyncCommand.php
├── Exceptions/
├── Events/SyncEvent.php
├── Facades/SyncroSheet.php
├── Jobs/PartialSyncJob.php
├── Models/
│   ├── SyncState.php                # Tracks sync operations
│   └── SyncEntry.php                # Tracks individual record syncs
├── Notifications/
├── Services/
│   ├── SyncManager.php              # Main orchestrator
│   ├── BatchProcessor.php           # Chunked batch processing (DB→Sheet)
│   ├── SheetReader.php              # Read sheet data (Sheet→DB)
│   ├── RecordMatcher.php            # Match sheet rows to DB records
│   ├── StateManager.php             # Sync state lifecycle
│   ├── GoogleClient.php             # Google Sheets API wrapper
│   ├── DataTransformer.php          # Model→row conversion
│   ├── SheetRowMapper.php           # Hash-based row identity
│   ├── FuzzyRecordIdentifier.php    # Heuristic field identification
│   ├── TokenManager.php             # OAuth token caching
│   ├── ErrorHandler.php             # Retry logic
│   ├── SyncLogger.php               # Dedicated logging
│   └── NotificationManager.php      # Event dispatch
└── LaravelSyncroSheetProvider.php
```

## SheetSyncable Contract

Models must implement `SheetSyncable`:

```php
interface SheetSyncable
{
    public function getSheetIdentifier(): string;  // Spreadsheet ID
    public function getSheetName(): string;         // Sheet/tab name
    public function toSheetRow(): array;            // Model → row data
}
```

**Optional methods** (checked via `method_exists`):
- `getIdColumnOnSheet(): ?string` — Column header for storing DB record IDs
- `fromSheetRow(array $row): array` — Reverse transform: sheet row → model attributes
- `getSyncDirection(): string` — `'to_sheet'`, `'from_sheet'`, `'bidirectional'`
- `defaultSheetHeaders(): array` — Custom header row
- `getBatchSize(): ?int` — Custom batch size
- `getPreferredSyncMode(): ?string` — `'append'` or `'replace'`
- `getSheetUniqueAttributes(): array` — Fields for hash-based dedup

## Sync Directions

| Direction | Command | Description |
|-----------|---------|-------------|
| `to_sheet` | `sheet:sync Model` | DB → Sheet (default) |
| `from_sheet` | `sheet:sync Model --direction=from_sheet` | Sheet → DB |
| `bidirectional` | `sheet:sync Model --direction=bidirectional` | Both directions |

## Development

```bash
composer install
composer test        # Run PHPUnit tests
composer lint        # Run Laravel Pint
```

### Testing

- Uses `orchestra/testbench` for Laravel package testing
- Test fixtures in `tests/Fixtures/`
- SQLite in-memory for test database

### Code Style

- PSR-4 autoloading
- PSR-12 code style (enforced by Laravel Pint)
- English-only code identifiers

## Git

- **Never** `git add .` — only add files you created or changed
- Commit messages: clear, imperative mood
