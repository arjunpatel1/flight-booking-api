<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Branch\Models\Branch;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('fact_kitchen_daily', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Branch::class)->nullable()->constrained()->nullOnDelete();
            $table->date('business_date');
            $table->unsignedBigInteger('kitchen_station_id')->nullable();
            $table->string('currency', 3)->nullable();
            
            // Order metrics
            $table->unsignedInteger('total_orders')->default(0);
            $table->unsignedInteger('completed_orders')->default(0);
            $table->unsignedInteger('delayed_orders')->default(0);
            
            // Time metrics
            $table->decimal('avg_preparation_time_minutes', 10, 2)->nullable();
            $table->decimal('avg_kot_delay_minutes', 10, 2)->nullable();
            $table->unsignedInteger('max_preparation_time_minutes')->nullable();
            
            // Performance metrics
            $table->decimal('orders_per_hour', 10, 2)->nullable();
            $table->decimal('on_time_percentage', 5, 2)->nullable();
            
            // Station capacity
            $table->unsignedInteger('max_concurrent_items')->nullable();
            $table->decimal('avg_concurrent_items', 10, 2)->nullable();
            
            $table->json('metadata')->nullable();
            $table->timestamp('calculated_at')->nullable();
            $table->timestamps();

            $table->unique(['branch_id', 'business_date', 'kitchen_station_id'], 'fkd_branch_date_station_unique');
            $table->index(['business_date', 'branch_id'], 'fkd_date_branch_idx');
            $table->index('kitchen_station_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fact_kitchen_dailies');
    }
};
