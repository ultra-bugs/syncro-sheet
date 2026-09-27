<?php

namespace Zuko\SyncroSheet\Contracts;

/**
 * Extended contract for models that participate in 2-way sync.
 *
 * Provides identity mapping on both sides (DB primary key ↔ sheet column),
 * enabling the system to correlate records without fuzzy matching.
 */
interface BidirectionalSyncable extends SheetSyncable
{
    /**
     * Column header on the sheet that stores the DB record's primary key.
     * This creates the bidirectional link between sheet rows and DB records.
     *
     * Example: return 'DB_ID' → sheet column header "DB_ID" holds the record's PK
     */
    public function getIdColumnOnSheet(): string;

    /**
     * Transform a sheet row (associative array keyed by header) into
     * model attributes suitable for create/update operations.
     *
     * Only return attributes that should be synced from sheet → DB.
     * Omit computed or read-only fields.
     */
    public function fromSheetRow(array $row): array;

    /**
     * Declared sync direction preference for this model.
     *
     * @return string 'to_sheet' | 'from_sheet' | 'bidirectional'
     */
    public function getSyncDirection(): string;
}
