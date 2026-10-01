<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('waiter_daily_collections', function (Blueprint $table) {
            // Additional performance metrics
            $table->unsignedInteger('cancellation_count')->default(0);
            $table->decimal('cancellation_amount', 18, 4)->default(0);
            $table->decimal('average_service_time_minutes', 10, 2)->nullable();
            $table->unsignedInteger('table_turnover_count')->default(0);
            $table->decimal('cancellation_percentage', 5, 2)->nullable();
            
            // Indexes
            $table->index('cancellation_count');
        });
    }

    public function down(): void
    {
        Schema::table('waiter_daily_collections', function (Blueprint $table) {
            $table->dropIndex(['cancellation_count']);
            
            $table->dropColumn([
                'cancellation_count',
                'cancellation_amount',
                'average_service_time_minutes',
                'table_turnover_count',
                'cancellation_percentage',
            ]);
        });
    }
};
