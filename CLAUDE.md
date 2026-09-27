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
├── Contracts/
│   ├── SheetSyncable.php            # Base interface (DB → Sheet)
│   └── BidirectionalSyncable.php    # Extended interface (DB ↔ Sheet)
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

## BidirectionalSyncable Contract

For 2-way sync, models implement `BidirectionalSyncable extends SheetSyncable`:

```php
interface BidirectionalSyncable extends SheetSyncable
{
    public function getIdColumnOnSheet(): string;   // Sheet column header storing DB PK
    public function fromSheetRow(array $row): array; // Sheet row → model attributes
    public function getSyncDirection(): string;      // 'to_sheet' | 'from_sheet' | 'bidirectional'
}
```

The system uses `instanceof BidirectionalSyncable` checks (not `method_exists`).

**Optional methods on SheetSyncable** (checked via `method_exists`):
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
| `incremental` | `sheet:sync Model --incremental` | Hash-based change detection, only sync changed rows |

## Change Detection (ContentHasher)

`ContentHasher` computes `md5` of normalized `toSheetRow()` output, stored in `sync_entries.content_hash`.

- `incrementalSync()`: compares current hash vs stored hash → only syncs changed/new records
- `fullSync()` and `processFromSheet()` also store hashes for future incremental runs
- Normalization: trims strings, casts numerics to float-string, null → empty string
- For bidirectional incremental: also discovers new sheet rows (no idCol value)

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
