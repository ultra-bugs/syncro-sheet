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
use Zuko\SyncroSheet\Services\SyncManager;

return new class extends Migration
{
    public function up()
    {
        Schema::table('sync_states', static function (Blueprint $table) {
            $table->enum('sync_mode', SyncManager::AVAILABLE_SYNC_MODES)
                ->default(SyncManager::AVAILABLE_SYNC_MODES[0])
                ->after('sync_type')
                ->index();
        });
    }

    public function down()
    {
        Schema::table('sync_states', static function (Blueprint $table) {
            $table->dropIndex('sync_states_sync_mode_index');
        });

        Schema::table('sync_states', static function (Blueprint $table) {
            $table->dropColumn('sync_mode');
        });
    }
};
