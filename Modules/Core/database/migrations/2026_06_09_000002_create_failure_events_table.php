<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('failure_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type')->index();
            $table->string('component')->index();
            $table->string('severity')->index(); // critical, error, warning, info
            $table->text('message');
            $table->json('context')->nullable();
            $table->timestamp('occurred_at')->index();
            $table->json('metadata')->nullable();
            $table->timestamps();

            // Composite indexes for common queries
            $table->index(['type', 'occurred_at']);
            $table->index(['component', 'occurred_at']);
            $table->index(['severity', 'occurred_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('failure_events');
    }
};
