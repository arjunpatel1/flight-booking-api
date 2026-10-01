<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voice_diagnostic_runs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('agent_id')->nullable();
            $table->boolean('all_passed')->default(false);
            $table->unsignedInteger('duration_ms')->default(0);
            $table->string('backend_connectivity', 10)->default('fail');
            $table->string('websocket_connectivity', 10)->default('fail');
            $table->string('audio_device_test', 10)->default('fail');
            $table->string('tts_engine_test', 10)->default('fail');
            $table->string('voice_queue_health', 10)->default('fail');
            $table->string('end_to_end_test', 10)->default('warn');
            $table->json('results')->nullable();
            $table->timestamp('ran_at');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['branch_id', 'ran_at']);
            $table->index(['agent_id', 'ran_at']);

            $table->foreign('branch_id')->references('id')->on('branches')->onDelete('cascade');
            // Note: agent_id foreign key removed to avoid cross-module migration dependency issues
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voice_diagnostic_runs');
    }
};
