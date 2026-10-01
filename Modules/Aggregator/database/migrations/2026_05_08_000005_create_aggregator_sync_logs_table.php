<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Aggregator\Enums\AggregatorSyncStatus;
use Modules\Aggregator\Enums\AggregatorSyncType;
use Modules\Aggregator\Models\AggregatorIntegration;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('aggregator_sync_logs', function (Blueprint $table) {
            $table->id();
            $table->createdBy();
            $table->foreignIdFor(AggregatorIntegration::class)->nullable()->constrained()->nullOnDelete();
            $table->enum('type', AggregatorSyncType::values())->index();
            $table->enum('status', AggregatorSyncStatus::values())->index();
            $table->string('reference')->nullable()->index();
            $table->text('message')->nullable();
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedInteger('max_attempts')->default(3);
            $table->dateTime('next_retry_at')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('aggregator_sync_logs');
    }
};
