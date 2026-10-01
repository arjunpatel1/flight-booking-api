<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_log', function (Blueprint $table) {
            $table->index(['created_at', 'id'], 'activity_log_created_id_index');
            $table->index(['causer_type', 'causer_id', 'created_at'], 'activity_log_causer_created_index');
            $table->index(['event', 'created_at'], 'activity_log_event_created_index');
            $table->index(['log_name', 'created_at'], 'activity_log_name_created_index');
        });
    }

    public function down(): void
    {
        Schema::table('activity_log', function (Blueprint $table) {
            $table->dropIndex('activity_log_created_id_index');
            $table->dropIndex('activity_log_causer_created_index');
            $table->dropIndex('activity_log_event_created_index');
            $table->dropIndex('activity_log_name_created_index');
        });
    }
};
