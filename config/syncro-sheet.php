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
        'sync_mode' => 'append',
        'timeout' => 600,
        'retries' => 3,
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
