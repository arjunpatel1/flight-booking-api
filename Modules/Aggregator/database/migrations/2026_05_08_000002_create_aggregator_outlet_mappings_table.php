<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Aggregator\Models\AggregatorIntegration;
use Modules\Branch\Models\Branch;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('aggregator_outlet_mappings', function (Blueprint $table) {
            $table->id();
            $table->createdBy();
            $table->foreignIdFor(AggregatorIntegration::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(Branch::class)->constrained()->cascadeOnDelete();
            $table->string('external_outlet_id');
            $table->string('external_outlet_name')->nullable();
            $table->json('meta')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['aggregator_integration_id', 'branch_id'], 'agg_outlet_agg_branch_unique');
            $table->unique(['aggregator_integration_id', 'external_outlet_id'], 'agg_outlet_agg_external_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('aggregator_outlet_mappings');
    }
};
