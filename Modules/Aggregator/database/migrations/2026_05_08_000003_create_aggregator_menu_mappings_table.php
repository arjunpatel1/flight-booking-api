<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Aggregator\Models\AggregatorIntegration;
use Modules\Menu\Models\Menu;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('aggregator_menu_mappings', function (Blueprint $table) {
            $table->id();
            $table->createdBy();
            $table->foreignIdFor(AggregatorIntegration::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(Menu::class)->constrained()->cascadeOnDelete();
            $table->string('external_menu_id')->nullable();
            $table->json('meta')->nullable();
            $table->boolean('sync_enabled')->default(true)->index();
            $table->dateTime('last_synced_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['aggregator_integration_id', 'menu_id'], 'agg_menu_agg_menu_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('aggregator_menu_mappings');
    }
};
