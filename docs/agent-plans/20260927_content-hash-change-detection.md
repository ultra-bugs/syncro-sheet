# Content Hash Change Detection for Incremental Sync

**Date**: 2026-09-27  
**Status**: Implementation  
**Relates to**: Bidirectional sync, partial sync, change detection

## Problem Statement

### Failure Case 1: Partial Sync Has No Change Detection

Current `partialSync()` pushes specific record IDs from DB→Sheet without checking if content actually changed. There's no mechanism to determine "which rows changed since last sync" — the system re-syncs everything blindly.

For developers wanting incremental sync (only process changed rows), the current design forces a full sheet read every time, which:
- Wastes Google Sheets API quota
- Becomes slow on large sheets (1000+ rows)
- Cannot scale to scheduled syncs (cron every 5 min)

### Failure Case 2: New Sheet Rows Silently Ignored

When someone manually adds rows to the sheet (without a DB ID in `idCol`):
- `fullSync(direction: 'to_sheet')` → never reads the sheet → new rows invisible
- `partialSync()` → processes only specified record IDs → new rows invisible
- Only `syncFromSheet()` and `bidirectionalSync()` discover new sheet rows

## Solution: Content Hashing

Store a deterministic hash of each record's `toSheetRow()` output in `sync_entries.content_hash`. On subsequent syncs, compare the current hash against the stored hash to detect changes.

### Hash Strategy

```
content_hash = md5(json_encode(normalize(toSheetRow())))
```

- `normalize()`: trim strings, cast numerics, sort keys (for associative arrays)
- `md5` chosen for speed — this is change detection, not security
- Hash is computed from `toSheetRow()` output only (the canonical representation)

### Why Hash Over `updated_at`

| Criterion | `updated_at` | Content Hash |
|-----------|-------------|--------------|
| Detects actual content change | No (timestamp updates on any save) | Yes |
| Works without timestamp column | No | Yes |
| Works for sheet-side changes | No | Yes (hash sheet row too) |
| Performance | O(1) lookup | O(n) compute, but cheap |
| Storage | None extra | 32 chars per entry |

Content hash is strictly more reliable. `updated_at` is a fast-path optimization that can be added later as an optional shortcut.

## Architecture

### Content Hash Flow (DB→Sheet)

```mermaid
flowchart TD
    A[incrementalSync called] --> B[Load all model records]
    B --> C[For each record: compute hash of toSheetRow]
    C --> D{Stored hash exists in sync_entries?}
    D -->|No| E[Mark as NEW - needs sync]
    D -->|Yes| F{Current hash == stored hash?}
    F -->|Yes| G[SKIP - unchanged]
    F -->|No| H[Mark as CHANGED - needs sync]
    E --> I[Collect changed + new records]
    H --> I
    I --> J[BatchProcessor syncs only these records]
    J --> K[Store new content_hash in sync_entries]
```

### Sheet→DB Change Detection

```mermaid
flowchart TD
    A[syncFromSheet or bidirectionalSync] --> B[Read full sheet data]
    B --> C[partitionByIdColumn]
    C --> D{Row has idCol value?}
    D -->|Yes - Existing| E[Compute hash of sheet row]
    E --> F{Hash matches stored sheet_content_hash?}
    F -->|Yes| G[SKIP - unchanged on sheet]
    F -->|No| H[Detect field-level changes via fromSheetRow]
    H --> I[Update DB record]
    D -->|No - New| J[Create DB record via fromSheetRow]
    J --> K[Write back ID to sheet idCol]
    I --> L[Store updated hashes]
    K --> L
```

### Incremental Sync Sequence

```mermaid
sequenceDiagram
    participant Dev as Developer
    participant SM as SyncManager
    participant CH as ContentHasher
    participant DB as Database
    participant SE as SyncEntry
    participant Sheet as Google Sheet

    Dev->>SM: incrementalSync(ModelClass)
    SM->>DB: Load all model records
    loop Each record
        SM->>CH: hash(record.toSheetRow())
        CH-->>SM: currentHash
        SM->>SE: getLatestHash(model, recordId)
        SE-->>SM: storedHash (or null)
        SM->>SM: Compare hashes
    end
    SM->>SM: Collect changed/new records only
    SM->>Sheet: Sync only changed records (BatchProcessor)
    SM->>SE: Store new content_hash for each synced record
    Note over SM,Sheet: Optional: also discover new sheet rows
    SM->>Sheet: Read sheet for new rows (no idCol)
    SM->>DB: Create records from new sheet rows
    SM->>Sheet: Write back IDs to idCol
```

### SyncEntry with Content Hash

```mermaid
erDiagram
    SyncState ||--o{ SyncEntry : "has many"
    SyncEntry {
        int id PK
        int sync_state_id FK
        string model_class
        string record_id
        int sheet_row_number
        string content_hash "md5 of toSheetRow() at sync time"
        datetime synced_at
        string sync_type
        string status
        string error_message
    }
```

## Implementation Plan

### 1. Migration: Add `content_hash` to `sync_entries`

```php
Schema::table('sync_entries', function (Blueprint $table) {
    $table->string('content_hash', 32)->nullable()->after('sheet_row_number');
});
```

### 2. New Service: `ContentHasher`

```php
class ContentHasher
{
    public function hash(array $row): string;           // Normalize + md5
    public function hasChanged(string $current, ?string $stored): bool;
    public function getStoredHash(string $modelClass, $recordId): ?string;
}
```

### 3. StateManager Changes

- `recordBatchSync()` accepts optional `$contentHash` per record
- New: `getLatestHashes(string $modelClass, array $recordIds): array` — bulk lookup

### 4. SyncManager: `incrementalSync()`

- Computes hash for all records
- Compares against stored hashes
- Syncs only changed/new records
- For bidirectional: also discovers new sheet rows

### 5. Tests

- Hash consistency (same input → same hash)
- Hash sensitivity (different input → different hash)
- Incremental sync skips unchanged records
- Incremental sync catches changed records
- New sheet rows discovered in incremental mode
