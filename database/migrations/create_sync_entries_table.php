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
    public function up(): void
    {
        Schema::create('sync_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sync_state_id')->constrained()->cascadeOnDelete();
            $table->string('model_class');
            $table->bigInteger('record_id');
            $table->timestamp('synced_at');
            $table->enum('sync_type', ['full', 'partial']);
            $table->enum('status', ['success', 'failed']);
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['model_class', 'record_id', 'synced_at']);
            $table->index(['sync_state_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_entries');
    }
}; 
