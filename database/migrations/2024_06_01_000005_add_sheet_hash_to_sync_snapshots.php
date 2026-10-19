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

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The tracking tables live on the connection TrackingDatabase resolved.
     */
    public function getConnection(): ?string
    {
        return \Zuko\SyncroSheet\Services\TrackingDatabase::connectionName();
    }

    public function up(): void
    {
        Schema::table('sync_snapshots', static function (Blueprint $table) {
            // Baseline of the sheet side; content_hash is the DB side's. Null
            // (rows written before this column) = both sides share content_hash.
            $table->string('sheet_hash')->nullable()->after('content_hash');
        });
    }

    public function down(): void
    {
        Schema::table('sync_snapshots', static function (Blueprint $table) {
            $table->dropColumn('sheet_hash');
        });
    }
};
