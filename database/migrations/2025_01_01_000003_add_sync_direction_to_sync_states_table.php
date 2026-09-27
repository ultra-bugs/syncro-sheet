<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sync_states', static function (Blueprint $table) {
            $table->string('sync_direction', 20)
                ->default('to_sheet')
                ->after('sync_mode')
                ->index();
        });
    }

    public function down(): void
    {
        Schema::table('sync_states', static function (Blueprint $table) {
            $table->dropColumn('sync_direction');
        });
    }
};
