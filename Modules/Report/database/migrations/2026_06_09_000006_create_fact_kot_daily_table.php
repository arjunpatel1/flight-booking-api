<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Branch\Models\Branch;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('fact_kot_dailies', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Branch::class)->nullable()->constrained()->nullOnDelete();
            $table->date('business_date');
            $table->unsignedTinyInteger('hour')->nullable();
            $table->unsignedBigInteger('kitchen_station_id')->nullable();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->string('currency', 3)->nullable();
            
            // KOT metrics
            $table->unsignedInteger('total_kots')->default(0);
            $table->unsignedInteger('on_time_kots')->default(0);
            $table->unsignedInteger('delayed_kots')->default(0);
            
            // Time metrics
            $table->decimal('avg_kot_time_minutes', 10, 2)->nullable();
            $table->decimal('avg_delay_minutes', 10, 2)->nullable();
            
            // Volume metrics
            $table->unsignedInteger('total_quantity')->default(0);
            
            $table->json('metadata')->nullable();
            $table->timestamp('calculated_at')->nullable();
            $table->timestamps();

            $table->unique(['branch_id', 'business_date', 'hour', 'kitchen_station_id', 'product_id'], 'fkotd_branch_date_hour_station_product_unique');
            $table->index(['business_date', 'branch_id'], 'fkotd_date_branch_idx');
            $table->index(['business_date', 'hour'], 'fkotd_date_hour_idx');
            $table->index('kitchen_station_id');
            $table->index('product_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fact_kot_dailies');
    }
};
