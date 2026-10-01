<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('order_products', function (Blueprint $table) {
            // Kitchen operation timestamps
            $table->timestamp('kot_sent_at')->nullable();
            $table->timestamp('kitchen_started_at')->nullable();
            $table->timestamp('kitchen_completed_at')->nullable();
            $table->timestamp('served_at')->nullable();
            
            // Performance metrics
            $table->unsignedInteger('preparation_time_seconds')->nullable();
            $table->integer('delay_seconds')->nullable();
            
            // Indexes for performance
            $table->index('kot_sent_at');
            $table->index('kitchen_started_at');
            $table->index('kitchen_completed_at');
            $table->index('delay_seconds');
        });
    }

    public function down(): void
    {
        Schema::table('order_products', function (Blueprint $table) {
            $table->dropIndex(['kot_sent_at']);
            $table->dropIndex(['kitchen_started_at']);
            $table->dropIndex(['kitchen_completed_at']);
            $table->dropIndex(['delay_seconds']);
            
            $table->dropColumn([
                'kot_sent_at',
                'kitchen_started_at',
                'kitchen_completed_at',
                'served_at',
                'preparation_time_seconds',
                'delay_seconds',
            ]);
        });
    }
};
