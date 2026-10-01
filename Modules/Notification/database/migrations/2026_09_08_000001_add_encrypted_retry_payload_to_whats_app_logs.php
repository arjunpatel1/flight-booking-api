<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('whats_app_logs') && ! Schema::hasColumn('whats_app_logs', 'retry_payload')) {
            Schema::table('whats_app_logs', fn (Blueprint $table) => $table->longText('retry_payload')->nullable()->after('request_payload'));
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('whats_app_logs') && Schema::hasColumn('whats_app_logs', 'retry_payload')) {
            Schema::table('whats_app_logs', fn (Blueprint $table) => $table->dropColumn('retry_payload'));
        }
    }
};
