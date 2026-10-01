<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voice_alerts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('agent_id')->nullable();
            $table->string('type', 50);        // voice_offline|tts_failure|audio_device_missing|queue_backlog|announcement_failure
            $table->string('severity', 20);    // warning|critical
            $table->text('message');
            $table->json('context')->nullable();
            $table->string('status', 20)->default('active'); // active|acknowledged|resolved
            $table->timestamp('fired_at');
            $table->timestamp('resolved_at')->nullable();
            $table->unsignedBigInteger('resolved_by')->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'status', 'fired_at']);
            $table->index(['agent_id', 'status']);
            $table->index(['type', 'status']);

            $table->foreign('branch_id')->references('id')->on('branches')->onDelete('cascade');
            // Note: agent_id foreign key removed to avoid cross-module migration dependency issues
            $table->foreign('resolved_by')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voice_alerts');
    }
};
