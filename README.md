# SyncroSheet

***Laravel package for efficient, bidirectional synchronization between Eloquent models and Google Sheets — with state tracking, conflict resolution, and scheduling.***

**Last updated:** 2026-10-18 · verified against [`a046c9e`](https://github.com/ultra-bugs/syncro-sheet/commit/a046c9e9c5c28d06c860ab353fc54e6046690661)

> [!IMPORTANT]
> Available on Packagist — install with `composer require zuko/syncro-sheet`.
> Export, import, and two-way reconcile are all supported. For how the pieces fit
> together, read the [architecture overview](docs/COMPONENTS_OVERVIEW.md); for a
> complete model on a real sheet, read the [real-run example](docs/examples/real-run/README.md).

## Documentation

| # | Read | For | Answers |
|---|---|---|---|
| 1 | This README | Everyone | Install, configure, day-to-day usage |
| 2 | [architecture overview](docs/COMPONENTS_OVERVIEW.md) | Developers / contributors | Components, data flow, lifecycle, glossary |
| 3 | [real-run example](docs/examples/real-run/README.md) | Anyone wiring a real sheet | A ledger synced both ways on the live API: model contract, scenarios, results, pitfalls, runnable harness |
| 4 | [`docs/agent-plans/`](docs/agent-plans/) | Contributors | Dated design records of each change |

## Table of Contents

- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
  - [Tracking database](#tracking-database)
- [Basic Usage](#basic-usage)
- [Advanced Usage](#advanced-usage)
- [Best Practices](#best-practices)
- [Contributing](#contributing)
- [License](#license)

## Features

- 🔄 Bidirectional sync between Laravel models and Google Sheets
  - **Export** (DB → Sheet) via `SheetExportable`
  - **Import** (Sheet → DB) via `SheetImportable`, upsert-by-primary-key
  - **Bidirectional** model = `SheetSyncable` (extends both of the above)
  - **Two-way reconcile** (`sync()`) — detects which side changed since the last
    sync and propagates it, resolving genuine conflicts by a fixed source-of-truth
- 🔑 Key by column position — the id column may have no header; rows typed on
  the sheet are created and get their new key written back
- 🧩 Model hooks: import gate for those rows (`shouldImportSheetRow()`), a
  per-model `DataTransformer` (`sheetTransformer()`), read-only (formula)
  columns, hash-ignored columns
- 🔢 Typed cells: numbers stay numbers, formulas and sheet-only columns survive
- 🗄️ Tracking tables kept apart from your data (own SQLite file by default)
- 🚦 Quota-aware: client-side throttle + retry with backoff on 429 / 5xx
- ✅ Verified end-to-end on the live API + PostgreSQL —
  [real-run example](docs/examples/real-run/README.md)
- 📦 Batch processing with memory efficiency
- 🎯 Support for both full and partial syncs
- 💾 Sophisticated state tracking and resume capability
- 🔁 Automatic retry mechanism with exponential backoff
- 📊 Detailed sync history and progress tracking
- 🚨 Comprehensive notification system
- 🔑 Smart token management for Google API

## Requirements

- PHP `>= 8.1`
- Laravel `>= 10` (`illuminate/database >= 10`)
- `revolution/laravel-google-sheets >= 6.0` (installed as a dependency)
- `pdo_sqlite` recommended — the tracking tables get their own SQLite file
  (without it they go to your default connection, see
  [Tracking database](#tracking-database))
- Google Sheets API enabled for your credentials

## Installation

```bash
composer require zuko/syncro-sheet
```

Publish the configuration (optional — the defaults are merged automatically):

```bash
php artisan vendor:publish --provider="Zuko\SyncroSheet\LaravelSyncroSheetProvider" --tag=config
php artisan migrate
```

> Migrations are not published: by default the tracking tables go to the
> package's own SQLite file, created on first boot; with
> `SYNCRO_SHEET_DB_CONNECTION` set (or without `pdo_sqlite`) the package's
> migrations run with your `php artisan migrate` — see
> [Tracking database](#tracking-database).

## Configuration

### Google Sheets Setup

1. Create a Google Cloud Project
2. Enable Google Sheets API
3. Create a Service Account (recommended for servers) or OAuth 2.0 credentials
4. Share the spreadsheet with the service account's `client_email` (Editor)
5. Tell the package which credentials to use. The first match wins:

| # | Setting | Value |
|---|---|---|
| 1 | `syncro-sheet.google.credentials` (`SYNCRO_SHEET_GOOGLE_CREDENTIALS`) | Service-account JSON path, or the decoded array (a host module can set it at boot). Used by this package only. |
| 2 | `GOOGLE_SERVICE_ENABLED=true` + `GOOGLE_SERVICE_ACCOUNT_JSON_LOCATION` | revolution/laravel-google-sheets' app-wide service account (`config/google.php`). |
| 3 | `GOOGLE_CLIENT_ID` / `GOOGLE_CLIENT_SECRET` / `GOOGLE_REDIRECT` | OAuth; the token is read from the cache (`TokenManager`). |

```env
# 1. package only
SYNCRO_SHEET_GOOGLE_CREDENTIALS=/absolute/path/service-account.json

# 2. or app-wide (also used by anything else calling the Sheets facade)
GOOGLE_SERVICE_ENABLED=true
GOOGLE_SERVICE_ACCOUNT_JSON_LOCATION=/absolute/path/service-account.json
```

With none of them, every run fails at its first API call with
`GoogleSheetsException: No Google credentials configured …` (the run is marked
`failed` with that message).

### Package Configuration

```php
// config/syncro-sheet.php

return [
    'defaults' => [
        'batch_size'      => 100,
        'direction'       => 'export',   // export | import | bidirectional
        'source_of_truth' => 'db',       // db | sheet (conflict winner on reconcile)
        'timeout'         => 600,
        'retries'         => 3,
        'export'          => ['write_mode' => 'append'],      // append | replace
        'import'          => ['use_batch_upsert' => false],
        'reconcile'       => ['hash_ignore' => ['updated_at']],
    ],
    'database' => [
        'connection'      => env('SYNCRO_SHEET_DB_CONNECTION'),   // null = own SQLite file (see below)
        'sqlite_database' => env('SYNCRO_SHEET_SQLITE_DATABASE'), // null = storage/syncro-sheet/tracking.sqlite
    ],
    'google' => [
        'credentials'     => env('SYNCRO_SHEET_GOOGLE_CREDENTIALS'),     // path | array | null (see above)
    ],
    // 'logging', 'sheets', 'notifications', 'scheduler' — see the published config
];
```

### Tracking database

Run history and reconcile baselines (`sync_states`, `sync_entries`,
`sync_snapshots`) are kept apart from your business data. The provider decides
where, on boot:

| `database.connection` | `pdo_sqlite` | Tracking tables live in | Schema |
|---|---|---|---|
| set (e.g. `pgsql`) | — | that connection | `php artisan migrate` |
| `null` | available | its own SQLite file (`storage/syncro-sheet/tracking.sqlite`, connection `syncro-sheet`, WAL) | created on first boot; pending migrations applied after every `php artisan migrate` and by `php artisan syncro-sheet:migrate` |
| `null` | missing | the app's default connection | `php artisan migrate` — and a warning (at most once a day) on both the package log and the app's default log channel |

> Upgrading from a version that kept them in the app database: set
> `SYNCRO_SHEET_DB_CONNECTION` to that connection to keep using the existing
> rows; otherwise the SQLite file starts empty (the next two-way sync only
> re-baselines records — rows that already agree are not rewritten).

## Basic Usage

### 1. Make Your Model Sync-able

```php
use Zuko\SyncroSheet\Contracts\SheetExportable;

class Product extends Model implements SheetExportable
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
```

Run history lives in `SyncState` — see [State Management](#4-state-management).

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

Make your model importable by implementing `SheetImportable` (or `SheetSyncable`
if it exports too, as below). The `InteractsWithSheet` trait provides defaults
(`getSheetKeyColumn()` returns the model key name, `getSheetKeyColumnIndex()`
its position in the exported row, `fromSheetRow()` maps headers straight to
attributes), so most models only need to declare the interface:

```php
use Zuko\SyncroSheet\Contracts\SheetSyncable; // = SheetExportable + SheetImportable
use Zuko\SyncroSheet\Traits\InteractsWithSheet;

class Product extends Model implements SheetSyncable
{
    use InteractsWithSheet;

    // ...export methods (getSheetIdentifier/getSheetName/toSheetRow)...

    // Override only when the sheet layout differs from the table:
    public function getSheetKeyColumn(): string
    {
        return 'id'; // attribute / header name of the primary key
    }

    public function getSheetKeyColumnIndex(): int
    {
        return 23; // 0-based: the key lives in column X (its header may be blank)
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

> **Primary key is required.** A bidirectional model (`SheetSyncable`) must
> expose its key column in the exported row — the key has to round-trip through
> the sheet so import can match
> rows back. If `getSheetKeyColumn()` is not at position
> `getSheetKeyColumnIndex()` of `toSheetRow()` / `defaultSheetHeaders()`, both
> `fullSync()` and `importFull()` throw a `SyncException` up front.
>
> **Matching & semantics:** rows are matched to records by the key cell at
> `getSheetKeyColumnIndex()` (read by position; a non-blank header there must
> be `getSheetKeyColumn()`). Writes are **upsert** — matched rows update,
> new rows create, nothing is deleted. Set
> `syncro-sheet.defaults.import.use_batch_upsert = true` to use `Model::upsert()`
> (one query per chunk, skips model events/mutators) for large trusted imports.
> Models with a non-numeric (UUID/string) primary key are not supported yet
> (the `sync_entries.record_id` column is a `bigInteger`).
>
> - A row whose key is unknown to the DB is created **with that key**, even when
>   the key is guarded. On PostgreSQL the key sequence is then moved past
>   `MAX(key)` so later inserts do not collide.
> - `fromSheetRow()` runs on the target record (the existing one, or a new
>   instance), so it can merge into attributes it already holds (e.g.
>   `array_merge($this->metadata ?? [], [...])`).
> - Soft-deleted records are left alone: never re-created, never updated.
> - **Rows without a key** (typed on the sheet) are created in the DB and only
>   the new key is written back, into their key cell. The rest of the row stays
>   as typed (formulas, blanks, spacing), and the rows match from then on.
>   Whether a row may become a record is the model's call:
>   `InteractsWithSheet::canImportSheetRow()` asks the model's
>   `shouldImportSheetRow(array $row): bool` hook when it defines one (date
>   separators, totals and blank lines answer `false`); a row whose
>   `fromSheetRow()` is `[]` is never created either. Rows are re-read right
>   before the key is written: one that was edited or moved meanwhile (a row
>   inserted above) waits for the next run. Creates and the write-back share a
>   DB transaction, so a failed sheet write leaves no record behind.

### Sheet layout

`toSheetRow()` may return a **header-keyed** row or a **positional** list. A
positional row needs `defaultSheetHeaders()`, which names each position (use
`''` for a column the model does not own):

```php
public function defaultSheetHeaders(): array
{
    return ['Date', 'Site', 'Dep', 'Out', 'Profit', '', 'id']; // '' = not ours
}

public function toSheetRow(): array
{
    return [$this->day, $this->site, (float) $this->dep, (float) $this->out, (float) $this->profit, '', $this->id];
}
```

- Values are placed by the **sheet's** header row: column order may differ from
  the model, and sheet-only columns (and `''` positions) are never written.
- Return `int`/`float` for numeric cells: values are written `RAW`, so a number
  stays a numeric cell (formulas and pivots can sum it) while a string — even
  `"10.00"` — is stored as text. Change detection compares numerics by value.
- Cells are read typed (`UNFORMATTED_VALUE`, dates as displayed); see
  `syncro-sheet.sheets.read`.
- Sheet-managed columns (formulas) go in `syncReadOnlyColumns(): array` — they
  are written when a row is appended, never overwritten afterwards, and ignored
  by change detection.
- New rows are appended after the last row in use, anchored on column A; an
  empty sheet gets the header row first.
- Sheet names are always quoted, so tabs named like a cell (`Oct25`, `2025`)
  are addressed correctly.
- API quota: requests are throttled client-side (`sheets.rate_limit`, default
  60/min — Google's default per-user quota) and quota (429) / transient (5xx)
  errors are retried with exponential backoff (`sheets.retry`).

### 5. Bidirectional (two-way reconcile)

For a bidirectional model (`SheetSyncable`, i.e. `SheetExportable` +
`SheetImportable`), a single `sync()` call reconciles both directions:

```php
use Zuko\SyncroSheet\Facades\SyncroSheet;

$syncState = SyncroSheet::sync(Product::class); // direction = 'bidirectional'
```

```bash
php artisan sheet:sync Product --direction=both
```

**Dry run first** on a tab that already holds data: `preview()` reads both
sides and reports what a run would do, without writing anything or recording
a run.

```php
app(\Zuko\SyncroSheet\Services\SyncManager::class)->preview(Product::class);
// ['create' => 4102, 'import' => 0, 'update' => 0, 'append' => 0, 'baseline' => 0]
```

```bash
php artisan sheet:sync Product --direction=both --dry-run
```

A first run that would both `create` (sheet rows without a key) and `append`
(DB records not on the sheet) usually means two copies of the same history.
Settle which side is complete before running it.

How it decides, per record (matched by primary key):

- A content-hash **baseline per side** is kept in `sync_snapshots`:
  `content_hash` is the DB side's form at the last sync and `sheet_hash` the
  sheet side's form. Each side is compared with its own baseline, so the two
  can differ in normalisation without counting as a change: trailing spaces,
  `0` against a blank cell, a formula against its value, a timestamp's
  precision. Rows written before `sheet_hash` existed use `content_hash` for
  both sides.
- Only the DB changed → the sheet row is updated in place, **cell by cell**:
  only the writable cells whose value differs from the sheet are written, so
  a formula whose value still matches is kept. Only the sheet changed → the
  record is upserted into the DB.
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
> **First run** (no baseline yet): records present on both sides that already
> agree are only baselined (nothing is written); records that differ are
> treated as conflicts and resolved by the source of truth.

### 6. Scheduling / automatic triggering

Sync on a schedule instead of calling it by hand. Configure which models sync,
how often, and which **driver** runs the work:

```php
// config/syncro-sheet.php
'scheduler' => [
    'enabled'   => true,
    'driver'    => 'sync',                 // 'sync' (inline) | 'queue' (fan out to jobs)
    'frequency' => 'everyFifteenMinutes',  // a Schedule method name, or a cron string
    'models'    => [
        Product::class => 'both',          // 'export' | 'import' | 'both'
        Order::class   => ['direction' => 'export'],
    ],
    'queue' => [
        'connection' => null,
        'queue'      => 'syncro-sheet',
        'chunk'      => 500,
    ],
],
```

When `enabled`, the package registers the `sheet:scheduled-sync` command on
Laravel's scheduler at the configured `frequency`. You can also run it by hand:

```bash
php artisan sheet:scheduled-sync
```

- **`sync` driver** — runs each model's sync inline, in the scheduler process.
- **`queue` driver** — the scheduler stays the dispatcher and fans work out to
  queued jobs: export reads the pending record IDs and dispatches `PartialSyncJob`
  per chunk; import reads the sheet and dispatches `ImportBatchJob` per chunk;
  bidirectional runs the conflict-resolution **decide** phase centrally
  (`Reconciler::decide()`) and dispatches `ReconcileApplyJob` per chunk to apply
  the result. Make sure a queue worker is running.

> A fork-based `parallel` driver (a true parallel consumer) is planned for a
> later stage.

## Advanced Usage

### 1. Custom Data Transformation

How a model's data is written to (and read from) the sheet lives in a
`DataTransformer`. Subclass it and name it from the model's `sheetTransformer()`
hook — `InteractsWithSheet::getSheetTransformer()` picks it up, and every write
(export, in-place update, append, key write-back), read and content hash of
that model goes through it.

```php
use Zuko\SyncroSheet\Contracts\SheetExportable;
use Zuko\SyncroSheet\Services\DataTransformer;

class ProductTransformer extends DataTransformer
{
    // Record -> sheet row (positional or header => value); formatted afterwards by formatValue().
    protected function transformRecord(SheetExportable $record): array
    {
        return [
            'id' => $record->id,
            'Product Name' => $record->name,
            'Price' => round((float) $record->price, 2), // keep it a number
            'In Stock' => $record->stock > 0,             // bool -> "Yes" / "No"
        ];
    }

    // Cell value on read (before fromSheetRow()).
    protected function parseValue($value)
    {
        return parent::parseValue($value);
    }
}

class Product extends Model implements SheetSyncable
{
    use InteractsWithSheet;

    public function sheetTransformer(): string
    {
        return ProductTransformer::class; // or an instance
    }
}
```

> Keep the codec symmetric: what `transformRecord()` / `formatValue()` write,
> `parseValue()` + `fromSheetRow()` must read back to the same attributes, or
> every import will change the record.

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
use Zuko\SyncroSheet\Exceptions\GoogleSheetsException;
use Zuko\SyncroSheet\Exceptions\SyncException;

try {
    $syncManager->fullSync(Product::class);
} catch (GoogleSheetsException $e) {
    // Handle Google Sheets specific errors
} catch (SyncException $e) {
    // Handle general sync errors
}
```

Every run ends in `completed` or `failed`:

- Whatever a run throws (an `\Error` included), its `SyncState` is marked
  `failed` with the message before the exception is rethrown, and the
  `sync.failed` event / failure notification follow.
- A run whose worker died (timeout kill, out of memory, deploy) is marked
  `failed` (`Abandoned: no result after N s`) by the next run of the same
  model, once it is older than `defaults.timeout` + 60 s.
- Only an export with known failed record ids is retried (a `PartialSyncJob`
  re-exports them); imports and two-way runs are not retried automatically.

## Best Practices

1. **Batch Size**: Adjust based on your model's complexity and memory constraints
```php
public function getBatchSize(): int
{
    return $this->hasMedia() ? 100 : 1000;
}
```

2. **Rate Limiting**: Configure based on your Google Sheets API quotas (Google's
   default is 60 requests/min/user, shared by everything using the same
   credentials)
```php
// config/syncro-sheet.php
'sheets' => [
    'rate_limit' => [
        'max_requests' => 60,
        'per_seconds' => 60,
    ],
    'retry' => [            // 429 / 5xx: exponential backoff + jitter
        'times' => 5,
        'base_delay_ms' => 2000,
    ],
],
```

3. **One writer per tab**: do not let another process (an observer pushing
   rows, a script) write the same tab while a two-way sync runs. List every
   formula column in `syncReadOnlyColumns()`. When a record changes in the
   DB, only the cells whose value changed are written; a formula elsewhere is
   replaced only if the DB changes that cell's value.

4. **Verify on your own sheet**: the [real-run harness](docs/examples/real-run/README.md)
   can be adapted to your model; each scenario edits one side, syncs, checks
   both sides through an independent client, then expects the next run to
   process 0.

5. **Error Handling**: Implement custom notification channels
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

Pull requests are welcome. For major changes, please open an issue first to discuss what you'd like to change.

## License

Released under the [MIT License](https://opensource.org/licenses/MIT).
