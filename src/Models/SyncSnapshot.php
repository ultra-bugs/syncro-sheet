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

namespace Zuko\SyncroSheet\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Baseline of a record's last-synced content, used by the reconciler to tell
 * which side (DB or sheet) changed since the previous bidirectional sync.
 */
class SyncSnapshot extends Model
{
    protected $fillable = [
        'model_class',
        'record_id',
        'content_hash',
        'synced_at',
    ];

    protected $casts = [
        'synced_at' => 'datetime',
    ];
}
