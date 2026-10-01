<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('voice_history', function (Blueprint $table) {
            $table->index(['order_id', 'event_type', 'created_at'], 'voice_history_order_event_created_idx');
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('voice_history', function (Blueprint $table) {
            $table->dropIndex('voice_history_order_event_created_idx');
        });

    }
};
