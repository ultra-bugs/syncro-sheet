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
        Schema::create('sync_snapshots', static function (Blueprint $table) {
            $table->id();
            $table->string('model_class');
            $table->bigInteger('record_id');
            $table->string('content_hash');
            $table->timestamp('synced_at');
            $table->timestamps();

            // One baseline row per record; used by the reconciler to detect
            // which side (DB or sheet) actually changed since the last sync.
            $table->unique(['model_class', 'record_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_snapshots');
    }
};
