<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voice_health_snapshots', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('agent_id')->nullable();
            $table->string('overall', 20)->default('offline');
            $table->string('voice_service_state', 20)->default('offline');
            $table->string('tts_engine_state', 20)->default('offline');
            $table->string('audio_device_state', 20)->default('offline');
            $table->string('queue_state', 20)->default('offline');
            $table->string('websocket_state', 20)->default('offline');
            $table->string('backend_state', 20)->default('offline');
            $table->string('last_announcement_state', 20)->default('offline');
            $table->json('subsystems')->nullable();
            $table->timestamp('checked_at');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['branch_id', 'checked_at']);
            $table->index(['agent_id', 'checked_at']);

            $table->foreign('branch_id')->references('id')->on('branches')->onDelete('cascade');
            // Note: agent_id foreign key removed to avoid cross-module migration dependency issues
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voice_health_snapshots');
    }
};
