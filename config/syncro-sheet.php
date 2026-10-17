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

return [
    /*
    |--------------------------------------------------------------------------
    | Default Settings
    |--------------------------------------------------------------------------
    */
    'defaults' => [
        'batch_size' => 100,
        'direction' => 'export',        // data flow: export (DB->Sheet) | import (Sheet->DB) | bidirectional
        'source_of_truth' => 'db',      // db | sheet — winning side on conflict (bidirectional, Phase 2)
        'timeout' => 600,
        'retries' => 3,

        'export' => [
            // How export writes to the sheet (NOT a direction): append rows, or
            // clear then write. Only meaningful for the export direction.
            'write_mode' => 'append',   // append | replace
        ],

        'import' => [
            // true  -> Model::upsert() (one query per chunk, skips model events/mutators)
            // false -> Model::updateOrCreate() per row (fires events, respects casts)
            'use_batch_upsert' => false,
        ],

        'reconcile' => [
            // Columns excluded from the content-hash change detection used by the
            // two-way reconcile (volatile fields that would otherwise mark every
            // record as "changed"). Models may override via syncHashIgnore().
            'hash_ignore' => ['updated_at'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging Configuration
    |--------------------------------------------------------------------------
    */
    'logging' => [
        'channel' => env('SHEET_SYNC_LOG_CHANNEL', 'sheet-sync'),
        'level' => env('SHEET_SYNC_LOG_LEVEL', 'info'),
        'separate_files' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Google Sheets Settings
    |--------------------------------------------------------------------------
    */
    'sheets' => [
        'cache_ttl' => 3600,
        'rate_limit' => [
            'max_requests' => 100,
            'per_seconds' => 60,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Notification Settings
    |--------------------------------------------------------------------------
    */
    'notifications' => [
        'channels' => ['mail'], // mail, slack, etc.
        'notify_on' => [
            'error' => true,
            'retry_exhausted' => true,
            'sync_completed' => false,
        ],
    ],
];
