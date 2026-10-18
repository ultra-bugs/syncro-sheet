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
    | Tracking database
    |--------------------------------------------------------------------------
    | Where sync_states / sync_entries / sync_snapshots live. Left null, the
    | package uses its own SQLite file when pdo_sqlite is available (schema
    | managed by the package, see `php artisan syncro-sheet:migrate`), and
    | falls back to the app's default connection otherwise (with a warning on
    | the package log and the app's default log channel).
    */
    'database' => [
        'connection' => env('SYNCRO_SHEET_DB_CONNECTION'),        // e.g. 'pgsql' to keep them in the app DB
        'sqlite_database' => env('SYNCRO_SHEET_SQLITE_DATABASE'), // null = storage/syncro-sheet/tracking.sqlite
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
    | Google credentials
    |--------------------------------------------------------------------------
    | Service account used by the package's own client only: a JSON file path
    | or the decoded array (a host module can set it at boot). Null falls back
    | to revolution/laravel-google-sheets: google.service.* when
    | GOOGLE_SERVICE_ENABLED=true, OAuth otherwise.
    */
    'google' => [
        'credentials' => env('SYNCRO_SHEET_GOOGLE_CREDENTIALS'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Google Sheets Settings
    |--------------------------------------------------------------------------
    */
    'sheets' => [
        // How cells are read. UNFORMATTED_VALUE returns typed values (numbers
        // as numbers, no thousands separators / display rounding) so change
        // detection and imports see the real value; FORMATTED_STRING returns
        // date cells as displayed instead of serial numbers.
        'read' => [
            'value_render_option' => 'UNFORMATTED_VALUE',
            'date_time_render_option' => 'FORMATTED_STRING',
        ],
        'cache_ttl' => 3600,
        // Client-side throttle. Google's default quota is 60 read and 60 write
        // requests per minute per user; this counts both together.
        'rate_limit' => [
            'max_requests' => 60,
            'per_seconds' => 60,
        ],
        // Quota (429) and transient (5xx) errors are retried with exponential
        // backoff: base_delay_ms * 2^attempt (capped at 64 s) + jitter.
        'retry' => [
            'times' => 5,
            'base_delay_ms' => 2000,
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

    /*
    |--------------------------------------------------------------------------
    | Scheduler / trigger
    |--------------------------------------------------------------------------
    | Automated, configurable triggering of syncs. The scheduler is always the
    | dispatcher; the driver decides HOW the work runs:
    |   - 'sync'  : run inline inside the scheduled command
    |   - 'queue' : fan out to queued jobs (scheduler still reads + dispatches)
    | ('parallel', a fork-based consumer, is planned for a later stage.)
    */
    'scheduler' => [
        'enabled' => false,
        'driver' => 'sync',                    // sync | queue
        'frequency' => 'everyFifteenMinutes',  // Schedule method name, or a cron string
        'models' => [
            // Product::class => 'both',                 // export | import | both
            // Order::class   => ['direction' => 'export'],
        ],
        'queue' => [
            'connection' => null,              // null = default connection
            'queue' => 'syncro-sheet',
            'chunk' => 500,                    // records / rows per batch job
        ],
    ],
];
