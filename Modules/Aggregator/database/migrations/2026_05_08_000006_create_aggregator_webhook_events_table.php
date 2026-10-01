<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Aggregator\Enums\AggregatorSyncStatus;
use Modules\Aggregator\Models\AggregatorIntegration;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('aggregator_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(AggregatorIntegration::class)->nullable()->constrained()->nullOnDelete();
            $table->string('event_type')->nullable()->index();
            $table->string('external_event_id')->nullable()->index();
            $table->enum('status', AggregatorSyncStatus::values())->default(AggregatorSyncStatus::Pending->value)->index();
            $table->string('signature')->nullable();
            $table->string('source_ip')->nullable();
            $table->json('headers')->nullable();
            $table->json('payload');
            $table->text('error_message')->nullable();
            $table->dateTime('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['aggregator_integration_id', 'external_event_id'], 'agg_webhook_agg_event_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('aggregator_webhook_events');
    }
};
