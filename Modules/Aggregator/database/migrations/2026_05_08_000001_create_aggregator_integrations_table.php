<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Aggregator\Enums\AggregatorProvider;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('aggregator_integrations', function (Blueprint $table) {
            $table->id();
            $table->createdBy();
            $table->enum('provider', AggregatorProvider::values())->index();
            $table->string('name');
            $table->string('base_url')->nullable();
            $table->json('credentials')->nullable();
            $table->string('webhook_secret')->nullable();
            $table->json('settings')->nullable();
            $table->boolean('is_active')->default(false)->index();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('aggregator_integrations');
    }
};
