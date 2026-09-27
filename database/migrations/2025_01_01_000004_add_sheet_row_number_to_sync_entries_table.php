<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sync_entries', static function (Blueprint $table) {
            $table->unsignedInteger('sheet_row_number')->nullable()->after('record_id');
            $table->index(['model_class', 'sheet_row_number']);
        });
    }

    public function down(): void
    {
        Schema::table('sync_entries', static function (Blueprint $table) {
            $table->dropIndex(['model_class', 'sheet_row_number']);
            $table->dropColumn('sheet_row_number');
        });
    }
};
