# Component Overview

This document describes the components used in this package. A minimal diagram also provides to illustrate the flow and mind-set at package built-stage.
Namespace: `Zuko\SyncroSheet`
Composer package name: `zuko/syncro-sheet`
Laravel compatible provider: \Zuko\SyncroSheet\LaravelSyncroSheetProvider

# Glossary

Single source of truth for the vocabulary — read this first to avoid mixing up
the overlapping-sounding terms.

| Term | Kind | Role | Don't confuse with |
|---|---|---|---|
| **direction** | config / `sync_states.direction` | Data flow: `export` (DB→Sheet), `import` (Sheet→DB), `bidirectional` | `write_mode` |
| **write_mode** | config `export.write_mode` / `sync_states.write_mode` | HOW export writes the sheet: `append` or `replace`. Export-only | `direction` |
| **sync_type** | `sync_states.sync_type` | Scope of a run: `full` or `partial` | direction / write_mode |
| **source_of_truth** | config / model `getSyncSourceOfTruth()` | Winning side on a reconcile conflict: `db` or `sheet` | — |
| **SheetBound** | interface | Base: `getSheetIdentifier()`, `getSheetName()` | — |
| **SheetExportable** | interface | Model can export (`toSheetRow()`) | the old `SheetSyncable` |
| **SheetImportable** | interface | Model can import (`getSheetKeyColumn()`, `fromSheetRow()`) | — |
| **SheetSyncable** | interface | **Bidirectional** model — extends both of the above | old meaning (= export) |
| **Exporter** | service | Engine DB→Sheet (chunked write) | Importer |
| **Importer** | service | Engine Sheet→DB (chunked upsert by PK) | Exporter |
| **Reconciler** | service | Two-way engine: `decide()` (read + diff baseline) + `apply()` | Exporter/Importer |
| **DataTransformer** | service | Value codec both ways + `canonicalHash` (no state, no I/O) | an orchestrator |
| **StateManager** | service | Owns ALL tracking tables: SyncState + SyncEntry + SyncSnapshot | — |
| **SyncState** | model | One run: direction, sync_type, write_mode, status, counters | SyncEntry |
| **SyncEntry** | model | Per-record result within a run (audit/history) | SyncSnapshot |
| **SyncSnapshot** | model | Current content-hash baseline per record (reconcile) | a per-run log |
| **GoogleClient** | service | revolution wrapper: read/append/updateRows/headers/clear + rate limit | — |
| **SyncManager** | service | Orchestrator/entry: fullSync/partialSync, importFull/importPartial, sync | SyncScheduler |

# Component List & Summary

### 1. Core Components

#### `SyncManager` (Main Orchestrator)
- Primary entry point for sync operations
- Coordinates between all other components
- Handles high-level error management
- Ensures proper sync state tracking

#### Sync Contracts (Interfaces)
- `SheetBound` — shared base: `getSheetIdentifier()`, `getSheetName()`
- `SheetExportable extends SheetBound` — export (DB → Sheet): adds `toSheetRow()`
- `SheetImportable extends SheetBound` — import (Sheet → DB): adds
  `getSheetKeyColumn()` + `fromSheetRow()`. The `InteractsWithSheet` trait ships
  sensible defaults for both.
- `SheetSyncable extends SheetExportable, SheetImportable` — **bidirectional**
  marker; a two-way model implements this one interface.
```php
interface SheetBound
{
    public function getSheetIdentifier(): string;    // Google Sheet ID
    public function getSheetName(): string;          // Sheet name
}

interface SheetExportable extends SheetBound
{
    public function toSheetRow(): array;             // DB -> Sheet row
    // Optional: getBatchSize(): int (defaults to config), defaultSheetHeaders(): array
}

interface SheetImportable extends SheetBound
{
    public function getSheetKeyColumn(): string;     // sheet header mapped to PK
    public function fromSheetRow(array $row): array; // Sheet row -> model attributes
}

interface SheetSyncable extends SheetExportable, SheetImportable {}  // bidirectional
```

### 2. Data Processing Components

#### `Exporter` (DB → Sheet)
- Export engine (formerly `BatchProcessor`)
- Manages chunked data processing
- Uses Laravel's lazy loading for memory efficiency
- Reports progress to state manager
- Handles chunk-level errors
- Public API: `export()` (full), `exportPartial()` (specific record IDs)

#### `Importer` (Sheet → DB)
- Import engine, symmetric counterpart of `Exporter`
- Reads the sheet via `GoogleClient::readSheet()`, matches rows to records by a
  primary-key column (`SheetImportable::getSheetKeyColumn()`)
- Upsert semantics: `updateOrCreate()` per row (default) or `Model::upsert()`
  batch (opt-in via `syncro-sheet.defaults.import.use_batch_upsert`); never deletes
- Public API: `import()` (full), `importPartial()` (specific record IDs)

#### `Reconciler` (Sheet ⇄ DB)
- Two-way reconcile engine for bidirectional models (`SheetSyncable`)
- Matches by primary key; detects which side changed vs. a content-hash
  **baseline**; resolves genuine conflicts by a fixed source-of-truth
  (`getSyncSourceOfTruth()` / `config('syncro-sheet.defaults.source_of_truth')`)
- Writes DB→sheet via row-level `GoogleClient::updateRows()` (+ append for new),
  and sheet→DB via upsert; never deletes
- Public API: `reconcile()` (driven by `SyncManager::sync()`)

#### `DataTransformer` (value codec)
- The single place that converts values **both ways**: model → cell on export
  (`transformBatch`/`formatRow`/`formatValue`) and cell → model on import (`parseRow`)
- `canonicalHash()` builds a normalized content hash so the DB side and the sheet
  side compare on equal footing (reconcile change-detection)
- Holds no state and does no I/O — the engines call it

### 3. State Management Components

#### `StateManager`
- Owns **all** tracking tables and is the only service that writes them:
  - `SyncState` — status/history of each run
  - `SyncEntry` — per-record result within a run
  - `SyncSnapshot` — current content-hash baseline per record (reconcile)
- Run lifecycle: `initializeSync()` / `recordBatchSync()` / `completeSync()` / `failSync()`
- Reconcile baseline: `baselineFor()` loads the map, `storeSnapshots()` upserts it
  (previously a separate `SnapshotManager`, now merged here)

#### `SyncState` (Model)
```php
// Used for tracking sync state of one sync call
class SyncState extends Model
{
    protected $fillable = [
        'model_class',      // Model class name
        'sync_type',        // 'full' or 'partial'
        'direction',        // 'export', 'import' or 'bidirectional'
        'write_mode',       // 'append' or 'replace' (export write strategy)
        'status',           // 'running', 'completed', 'failed'
        'started_at',
        'completed_at',
        'total_processed',
        'last_processed_id',
    ];
}

// Used for tracking individual records
class SyncEntry extends Model
{
    protected $fillable = [
        'model_class',
        'record_id',
        'synced_at',
        'sync_state_id',    // Reference to parent SyncState
        'sync_type',        // 'full' or 'partial'
        'status'            // 'success' or 'failed'
    ];

    protected $casts = [
        'synced_at' => 'datetime'
    ];
}```

### 4. Google Sheets Integration

#### `SheetClient` (Wrapper around revolution/laravel-google-sheets)
- Manages Google Sheets connections
- Handles authentication
- Provides sheet operations interface
- Manages API rate limits

### 5. Logging & Monitoring

#### `SyncLogger`
- Dedicated logging channel
- Structured log format
- Performance logging
- Error tracking

#### `Events`
```php
class Events
{
    const SYNC_STARTED = 'sheet-sync.started';
    const CHUNK_PROCESSED = 'sheet-sync.chunk-processed';
    const SYNC_COMPLETED = 'sheet-sync.completed';
    const SYNC_FAILED = 'sheet-sync.failed';
}
```

### 6. Configuration

#### Package Config (`syncro-sheet.php`)
```php
return [
    'defaults' => [
        'batch_size' => 1000,
        'timeout' => 600,
        'retries' => 3
    ],

    'logging' => [
        'channel' => 'sheet-sync',
        'level' => 'info',
        'separate_files' => true
    ],

    'sheets' => [
        'cache_ttl' => 3600,
        'rate_limit' => [
            'max_requests' => 100,
            'per_seconds' => 60
        ]
    ]
];
```

# Component Interactions


```mermaid
graph TB
    A[SyncManager] --> B[Exporter]
    A --> B2[Importer]
    A --> C[StateManager]
    A --> D[GoogleClient]

    B --> E[DataTransformer]
    B2 --> E
    B --> D
    B2 --> D
    B --> F[SyncLogger]
    B2 --> F

    D --> G[Google Sheets API]

    C --> H[Database]
    B2 --> H
    F --> I[Log Files]

    J[Model] --> A
    J --> E
```


# Flow:

## Export flow (DB → Sheet)

```mermaid
sequenceDiagram
    participant Command as SyncCommand
    participant Manager as SyncManager
    participant Processor as Exporter
    participant State as StateManager
    participant Transformer as DataTransformer
    participant Client as GoogleClient

    Command->>Manager: fullSync(ModelClass)
    Manager->>State: initializeSync(direction=export)
    Manager->>Processor: export(ModelClass)

    loop For each chunk
        Processor->>Transformer: transformBatch(chunk)
        Transformer-->>Processor: rows
        Processor->>Client: appendWithHeaders(sheetId, rows)
        Client-->>Processor: result
        Processor->>State: recordBatchSync()
    end

    Manager->>State: completeSync()
    Manager-->>Command: SyncState
```

## Import flow (Sheet → DB)

```mermaid
sequenceDiagram
    participant Command as SyncCommand
    participant Manager as SyncManager
    participant Processor as Importer
    participant Client as GoogleClient
    participant Transformer as DataTransformer
    participant State as StateManager
    participant DB as Database

    Command->>Manager: importFull(ModelClass)
    Manager->>State: initializeSync(direction=import)
    Manager->>Processor: import(ModelClass)
    Processor->>Client: readSheet(sheetId)
    Client-->>Processor: [headers, ...rows]

    loop For each chunk
        Processor->>Transformer: parseRow(assoc)
        Transformer-->>Processor: normalized
        Note over Processor: model.fromSheetRow() -> attributes
        Processor->>DB: updateOrCreate(key, attributes)
        DB-->>Processor: record
        Processor->>State: recordBatchSync()
    end

    Manager->>State: completeSync()
    Manager-->>Command: SyncState
```
