<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('pos_sessions', function (Blueprint $table) {
            // Shift tracking
            $table->foreignId('shift_id')->nullable()->constrained('employee_shifts');
            
            // Performance metrics
            $table->decimal('sales_per_hour', 18, 4)->nullable();
            $table->decimal('orders_per_hour', 10, 2)->nullable();
            
            // Indexes
            $table->index('shift_id');
        });
    }

    public function down(): void
    {
        Schema::table('pos_sessions', function (Blueprint $table) {
            $table->dropIndex(['shift_id']);
            
            $table->dropForeign(['shift_id']);
            $table->dropColumn([
                'shift_id',
                'sales_per_hour',
                'orders_per_hour',
            ]);
        });
    }
};
