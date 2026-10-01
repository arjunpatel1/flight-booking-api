<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('recommendation_telemetry')) {
            return;
        }

        Schema::create('recommendation_telemetry', function (Blueprint $table) {
            $table->id();
            $table->string('tracking_id', 64)->index();
            $table->string('recommendation_type', 64)->nullable();
            $table->string('event', 24); // shown|accepted|ignored|successful|failed
            $table->foreignId('branch_id')->nullable()->index();
            $table->foreignId('user_id')->nullable();
            $table->decimal('confidence', 4, 2)->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recommendation_telemetry');
    }
};
