<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Aggregator\Models\AggregatorIntegration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('aggregator_order_mappings', function (Blueprint $table) {
            $table->id();
            $table->createdBy();
            $table->foreignIdFor(AggregatorIntegration::class)->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->index();
            $table->string('external_order_id');
            $table->string('external_order_number')->nullable();
            $table->string('external_status')->nullable();
            $table->json('payload')->nullable();
            $table->dateTime('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['aggregator_integration_id', 'external_order_id'], 'agg_order_agg_external_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('aggregator_order_mappings');
    }
};
