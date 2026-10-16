<?php
/*
 *          M""""""""`M            dP
 *          Mmmmmm   .M            88
 *          MMMMP  .MMM  dP    dP  88  .dP   .d8888b.
 *          MMP  .MMMMM  88    88  88888"    88'  `88
 *          M' .MMMMMMM  88.  .88  88  `8b.  88.  .88
 *          M         M  `88888P'  dP   `YP  `88888P'
 *          MMMMMMMMMMM    -*-  Created by Zuko  -*-
 *
 *          * * * * * * * * * * * * * * * * * * * * *
 *          * -    - -   F.R.E.E.M.I.N.D   - -    - *
 *          * -  Copyright © 2024 (Z) Programing  - *
 *          *    -  -  All Rights Reserved  -  -    *
 *          * * * * * * * * * * * * * * * * * * * * *
 */

namespace Zuko\SyncroSheet\Services;

use Illuminate\Support\Facades\DB;

/**
 * Reads and writes the per-record content-hash baseline in `sync_snapshots`.
 *
 * The reconciler compares the current DB-side and sheet-side hashes against
 * this baseline to decide which side changed since the last sync.
 */
class SnapshotManager
{
    /**
     * Load the baseline for a model as a map of record_id => content_hash.
     *
     * @return array<int|string, string>
     */
    public function baselineFor(string $modelClass): array
    {
        return DB::table('sync_snapshots')
            ->where('model_class', $modelClass)
            ->pluck('content_hash', 'record_id')
            ->all();
    }

    /**
     * Upsert the baseline for a batch of records.
     *
     * @param  array<int|string, string>  $hashesByRecordId  record_id => content_hash
     */
    public function storeMany(string $modelClass, array $hashesByRecordId): void
    {
        if (empty($hashesByRecordId)) {
            return;
        }

        $now = now();

        $rows = [];
        foreach ($hashesByRecordId as $recordId => $hash) {
            $rows[] = [
                'model_class' => $modelClass,
                'record_id' => $recordId,
                'content_hash' => $hash,
                'synced_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('sync_snapshots')->upsert(
            $rows,
            ['model_class', 'record_id'],
            ['content_hash', 'synced_at', 'updated_at']
        );
    }
}
