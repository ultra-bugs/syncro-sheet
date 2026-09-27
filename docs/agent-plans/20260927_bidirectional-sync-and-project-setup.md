# Bidirectional Sync + Project Setup

## Requirement

1. **idColumnOnSheet**: Support defining a column on the sheet to store DB record IDs, enabling tracking of which records are synced
2. **Bidirectional sync**: Sheet → DB direction (currently only DB → Sheet)
3. **Migration renaming**: Fix nonstandard migration filenames (0_, 1_, 2_) to standard Laravel format
4. **CLAUDE.md init**: Project documentation for agent workflow
5. **Unit tests setup**: phpunit.xml + test structure + basic tests
6. **Fix missing code**: Exception classes, TokenManager::getToken()
7. **BidirectionalSyncable interface**: Formal contract for models that participate in 2-way sync

## Migration Rename Map

| Old | New |
|-----|-----|
| `0_create_sync_states_table.php` | `2025_01_01_000000_create_sync_states_table.php` |
| `1_create_sync_entries_table.php` | `2025_01_01_000001_create_sync_entries_table.php` |
| `2_create_sync_mode_column.php` | `2025_01_01_000002_add_sync_mode_to_sync_states_table.php` |

New migrations added:
- `2025_01_01_000003_add_sync_direction_to_sync_states_table.php`
- `2025_01_01_000004_add_sheet_row_number_to_sync_entries_table.php`

---

## Contract Hierarchy

```mermaid
classDiagram
    class SheetSyncable {
        <<interface>>
        +getSheetIdentifier() string
        +getSheetName() string
        +toSheetRow() array
    }
    
    class BidirectionalSyncable {
        <<interface>>
        +getIdColumnOnSheet() string
        +fromSheetRow(array row) array
        +getSyncDirection() string
    }
    
    SheetSyncable <|-- BidirectionalSyncable
    
    class SheetSyncable {
        <<optional via method_exists>>
        getBatchSize() int?
        getPreferredSyncMode() string?
        defaultSheetHeaders() array
        getSheetUniqueAttributes() array
    }
    
    class BetTransaction {
        +getSheetIdentifier() string
        +getSheetName() string
        +toSheetRow() array
        +getIdColumnOnSheet() string
        +fromSheetRow(array) array
        +getSyncDirection() string
    }
    
    BidirectionalSyncable <|.. BetTransaction
    
    note for SheetSyncable "Base contract: DB → Sheet only\nAll models must implement this"
    note for BidirectionalSyncable "Extended contract: enables Sheet → DB\nProvides identity mapping on both sides"
```

**Design rationale**: `method_exists()` checks are fragile — no IDE support, no static analysis, no compile-time guarantee. `BidirectionalSyncable` formalizes the contract: if a model implements it, all 3 methods are guaranteed present. The system uses `instanceof` checks instead of `method_exists`.

### Identity Mapping

```
┌──────────────────────┐          ┌──────────────────────┐
│      Database         │          │    Google Sheet       │
│                       │          │                       │
│  PK: id (auto-incr)  │◄────────►│  Col: idColumnOnSheet │
│                       │          │  (e.g. column "X")    │
│  model attributes    │──toSheetRow()──►  row cells       │
│  model attributes    │◄─fromSheetRow()── row cells       │
└──────────────────────┘          └──────────────────────┘

getIdColumnOnSheet() = "X"  → Sheet column header storing DB PK
Model::getKeyName()  = "id" → DB primary key column (Eloquent default)
```

---

## Bidirectional Sync Flow

```mermaid
flowchart TB
    START([SyncManager::bidirectionalSync]) --> VALIDATE{instanceof<br/>BidirectionalSyncable?}
    VALIDATE -->|No| THROW[SyncException]
    VALIDATE -->|Yes| PHASE1

    subgraph PHASE1["Phase 1: DB → Sheet"]
        FS[fullSync] --> BP[BatchProcessor::process]
        BP --> DT[DataTransformer::transformBatch]
        DT --> GC_WRITE[GoogleClient::appendWithHeaders]
        GC_WRITE --> WB{idColumnOnSheet<br/>defined?}
        WB -->|Yes| WRITEBACK[writeBackIds:<br/>match DB rows to sheet rows<br/>write PK into idColumn cells]
        WB -->|No| SKIP1[skip]
    end

    PHASE1 --> PHASE2

    subgraph PHASE2["Phase 2: Sheet → DB"]
        READ[SheetReader::readSheetData] --> PARSE[Parse headers + rows<br/>rows keyed by sheet row number]
        PARSE --> PARTITION[SheetReader::partitionByIdColumn]
        
        PARTITION --> EXISTING["existing: rows WHERE<br/>idColumn IS NOT NULL"]
        PARTITION --> NEW["new: rows WHERE<br/>idColumn IS NULL"]
        
        EXISTING --> MATCH[RecordMatcher::matchByIds<br/>load DB records by PK values]
        MATCH --> DETECT[RecordMatcher::detectChanges<br/>compare fromSheetRow attrs vs DB]
        DETECT --> UPDATE["Update changed records<br/>record.update(new_attributes)"]
        
        NEW --> CREATE["Create new records<br/>ModelClass::create(fromSheetRow)"]
        CREATE --> WRITEID[writeIdsToSheet:<br/>write new PKs back to<br/>idColumn on sheet]
    end

    PHASE2 --> COMPLETE([SyncState: completed])
```

---

## SheetReader Parse Flow

```mermaid
flowchart LR
    subgraph INPUT["Raw Sheet Data"]
        RAW["GoogleClient::readSheet()<br/>returns array of arrays"]
    end

    subgraph PARSE["SheetReader::readSheetData"]
        H[Row 0 → headers]
        R["Rows 1..N → associative arrays<br/>keyed by sheet row number (2-indexed)"]
        PAD["Pad short rows to header count<br/>Slice long rows to header count"]
    end

    subgraph OUTPUT["Parsed Result"]
        HEADERS["headers: ['Name','Price','SKU','DB_ID']"]
        ROWS["rows: {<br/>  2: {Name:'Widget', Price:9.99, ...},<br/>  3: {Name:'Gadget', Price:19.99, ...}<br/>}"]
    end

    RAW --> H --> HEADERS
    RAW --> R --> PAD --> ROWS
```

### Why row numbers start at 2

Google Sheets API uses 1-indexed rows. Row 1 = headers. Data rows start at row 2. `SheetReader` preserves this so that cell references (e.g. `X5`) for `writeBackIds` are accurate.

---

## RecordMatcher Flow

```mermaid
flowchart TB
    subgraph matchByIds
        INPUT1["sheetRows: {rowNum → assoc data}<br/>dbIdMap: {rowNum → DB PK}"]
        LOAD["Load DB records:<br/>Model::whereIn(PK, dbIds)"]
        LOOP1["For each sheetRow:<br/>find PK in dbIdMap<br/>check if DB record exists"]
        MATCHED["matched: {rowNum → {sheet_data, db_record, db_id}}"]
        UNMATCHED["unmatched: {rowNum → row data}"]
        
        INPUT1 --> LOAD --> LOOP1
        LOOP1 -->|found| MATCHED
        LOOP1 -->|not found| UNMATCHED
    end

    subgraph detectChanges
        INPUT2["matched rows from above"]
        FROM["model.fromSheetRow(sheet_data)<br/>→ candidate attributes"]
        COMPARE["Compare each attribute:<br/>DateTimeInterface → format compare<br/>numeric → float compare<br/>else → string compare"]
        CHANGED["changed: {rowNum → {db_record, new_attributes, db_id}}"]
        UNCHANGED["unchanged: skipped"]
        
        INPUT2 --> FROM --> COMPARE
        COMPARE -->|differs| CHANGED
        COMPARE -->|same| UNCHANGED
    end

    matchByIds --> detectChanges
```

---

## SyncState Tracking with idColumnOnSheet

```mermaid
stateDiagram-v2
    [*] --> Running : StateManager::initializeSync()
    
    Running --> Completed : all batches processed
    Running --> Failed : exception caught

    state Running {
        [*] --> ProcessBatch
        ProcessBatch --> RecordEntries : success
        RecordEntries --> ProcessBatch : next batch
        RecordEntries --> WriteBackIds : all batches done

        state RecordEntries {
            state "SyncEntry per record" as SE
            SE : sync_state_id
            SE : model_class
            SE : record_id (DB PK)
            SE : sheet_row_number (nullable)
            SE : status = success
        }

        state "WriteBackIds (when idColumnOnSheet)" as WriteBackIds {
            state "Read sheet data" as RS
            state "Find rows without ID" as FW
            state "Match DB rows via fuzzy compare" as FM
            state "GoogleClient::updateCell" as UC
            RS --> FW --> FM --> UC
        }
    }

    Completed --> [*]
    Failed --> [*]
```

### SyncState record structure

```
sync_states
├── id
├── model_class      = "App\Models\BetTransaction"
├── sync_type        = "full" | "partial"
├── sync_mode        = "append" | "replace"
├── sync_direction   = "to_sheet" | "from_sheet" | "bidirectional"
├── status           = "running" | "completed" | "failed"
├── total_processed
├── last_processed_id
├── started_at
├── completed_at
└── error_message

sync_entries (per-record tracking)
├── sync_state_id    → FK to sync_states
├── model_class
├── record_id        = DB primary key value
├── sheet_row_number = Sheet row (nullable, set during from_sheet)
├── sync_type
├── status
└── synced_at
```

### Does SyncState tracking still work with idColumnOnSheet?

**Yes.** SyncState tracking is independent of the identity column:

| Scenario | SyncState behavior |
|---|---|
| `to_sheet` without idColumn | Records synced, entries created with `record_id`. No sheet row tracking. |
| `to_sheet` WITH idColumn | Same as above + `writeBackIds` writes PKs to sheet after sync. SyncEntries unchanged. |
| `from_sheet` with idColumn | SyncEntries created with both `record_id` AND `sheet_row_number`. New records get IDs written back to sheet. |
| `bidirectional` | Phase 1 creates entries for DB→Sheet. Phase 2 creates entries for Sheet→DB with row numbers. Two SyncState records (one per phase). |

The `idColumnOnSheet` is a **sheet-side concern** — it enables the system to correlate rows across syncs but doesn't alter how SyncState/SyncEntry track operations.

---

## Implementation Steps

### Phase 1: Fix & Cleanup (DONE)
1. ~~Rename migrations to standard format~~
2. ~~Create missing Exception classes~~
3. ~~Fix TokenManager::getToken()~~
4. ~~Init CLAUDE.md~~
5. ~~Fix notification missing imports~~
6. ~~Fix DataTransformer Collection type~~
7. ~~Fix BatchProcessor array_combine~~
8. ~~Fix migration down() SQLite compatibility~~

### Phase 2: BidirectionalSyncable Interface
1. Create `BidirectionalSyncable` interface extending `SheetSyncable`
2. Refactor `SyncManager` — replace `method_exists` with `instanceof BidirectionalSyncable`
3. Refactor `SheetReader` — replace `method_exists` with `instanceof`
4. Refactor `RecordMatcher` — replace `method_exists` with `instanceof`
5. Update `FakeProduct` test fixture to implement `BidirectionalSyncable`
6. Update `BetTransaction` in fitly to implement `BidirectionalSyncable`
7. Update tests, run linter
