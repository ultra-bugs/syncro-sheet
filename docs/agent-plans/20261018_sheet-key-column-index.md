# Primary key by column index + rows created on the sheet

**Date:** 2026-10-18 · **Follows:** [20261018_real-run-fixes.md](20261018_real-run-fixes.md)

## 1. Why

- The importable contract only names the key by **header text**
  (`getSheetKeyColumn()`). Real sheets often keep the id in a column with no
  header (Fitly's `Thu chi`: the id lives in column X, `X1` is blank), so the
  key cannot be found. The original intent (see the earlier
  `getIdColumnOnSheet()` design) is that the model tells **where** the key
  cell is.
- Rows typed by a person have no id yet. Today they are skipped, so a row
  added on the sheet never reaches the DB. Knowing the key cell's position
  lets the sync create the record and **write the new id back into that
  cell**, after which the row is matched like any other.

## 2. Contract

```php
interface SheetImportable extends SheetBound
{
    public function getSheetKeyColumn(): string;       // attribute / header name of the key
    public function getSheetKeyColumnIndex(): int;     // NEW: 0-based column of the key cell (A = 0)
    public function fromSheetRow(array $row): array;   // [] = this row is not a record
}
```

- `InteractsWithSheet` provides the default index: the position of
  `getSheetKeyColumn()` in `defaultSheetHeaders()` / `toSheetRow()` keys.
- The key is read from and written to that position whatever the sheet's
  header text is (blank allowed). Other columns are still matched by header.
- Guard: a bidirectional model must put its key at that position of its own
  exported row (`headers[index] === getSheetKeyColumn()`), so the key round-trips.

## 3. Rows without a key ("sheet-born")

```mermaid
sequenceDiagram
    autonumber
    participant R as Reconciler / Importer
    participant M as Model
    participant G as GoogleClient
    participant DB as Database
    R->>G: readSheet()
    Note over R: key cell empty → candidate<br/>(rowIndex, raw row)
    R->>M: fromSheetRow(row) on a new instance
    alt [] returned
        Note over R: not a record (separator, blank, …) → ignored
    else attributes
        R->>G: readRows(candidate rows)
        Note over R: row changed / moved since the read → skipped,<br/>retried next run
        R->>DB: BEGIN; create record
        R->>G: write the new id into (rowIndex, keyIndex)<br/>(reconcile: the whole writable row)
        R->>DB: COMMIT (rollback when the write fails → no orphan record)
    end
```

- **Verify before write**: rows are re-read right before the ids are written;
  a row whose cells differ from what was planned (edited, or shifted by a row
  inserted above) is left for the next run instead of receiving a wrong id.
- **Atomic**: creates and the id write-back share one DB transaction, so a
  failed sheet write leaves no DB record behind (which would otherwise be
  duplicated on the next run).
- Reconcile writes the record's full writable row back, so DB and sheet agree
  in one run; import writes the id cell only.
- `fromSheetRow()` returning `[]` marks a non-record row (date separators,
  totals, blank lines). For an existing record it means "nothing to update".

## 4. Steps

1. Contract + trait default + guard (`SyncManager`, `Reconciler`).
2. Read the key by position (`Reconciler::readSheet`, `Importer::prepareRows`);
   map the key column by position in the column layout.
3. `GoogleClient::readRows()` (one `values.batchGet`).
4. Sheet-born flow in `Reconciler` (plan action `create`) and `Importer`.
5. Tests: blank key header, keyless row create + write-back, non-record rows,
   moved row skipped, import direction, guard.
6. Fitly: `BetTransaction::getSheetKeyColumnIndex() = 23`, `fromSheetRow()`
   returns `[]` for rows that are not transactions; real run on a tab shaped
   like `Thu chi` (blank `X1`, date separator rows, rows without id).

## 5. Results (real run, live Sheets API + PostgreSQL)

- Tab shaped like `Thu chi` (43 raw rows: blank `X1`, `13/10`-style date
  separators, no ids, `Profit` formulas): one run created the 37 transaction
  rows, wrote ids 1..37 into X on the right rows, left separators, `Profit`
  formulas and day labels untouched; the next run processed 0.
- A row typed on the sheet, then a row inserted above it between `decide()`
  and `applyChunk()`: nothing created and no id written; the next run created
  it once with the id on the shifted row.
- Found on the way: the default throttle (100 req/min) exceeded Google's
  default quota (60/min/user) and one 429 failed the run → default lowered to
  60 and 429/5xx retried with exponential backoff (`sheets.retry`).
- The client-side throttle waited on the newest request of the window instead
  of the oldest (over-throttling) — fixed.
