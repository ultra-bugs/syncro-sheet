<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sync_entries', function (Blueprint $table) {
            $table->string('content_hash', 32)->nullable()->after('sheet_row_number');
        });
    }

    public function down(): void
    {
        Schema::table('sync_entries', function (Blueprint $table) {
            $table->dropColumn('content_hash');
        });
    }
};
