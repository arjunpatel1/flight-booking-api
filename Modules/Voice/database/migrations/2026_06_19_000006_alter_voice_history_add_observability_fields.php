<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('voice_history', function (Blueprint $table) {
            $table->string('playback_status', 20)->default('played')->after('success')
                  ->comment('queued|processing|played|failed|cancelled');
            $table->unsignedInteger('queue_delay_ms')->nullable()->after('duration')
                  ->comment('Time spent in queue before processing started');
            $table->unsignedInteger('processing_duration_ms')->nullable()->after('queue_delay_ms')
                  ->comment('Time for TTS synthesis');
            $table->unsignedInteger('playback_duration_ms')->nullable()->after('processing_duration_ms')
                  ->comment('Actual audio playback duration');
        });
    }

    public function down(): void
    {
        Schema::table('voice_history', function (Blueprint $table) {
            $table->dropColumn(['playback_status', 'queue_delay_ms', 'processing_duration_ms', 'playback_duration_ms']);
        });
    }
};
