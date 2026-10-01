<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('agent_telemetry', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agent_id');
            $table->string('agent_uuid', 80)->index();
            $table->decimal('cpu_percent', 5, 2)->nullable();
            $table->decimal('ram_used_mb', 10, 2)->nullable();
            $table->decimal('ram_total_mb', 10, 2)->nullable();
            $table->decimal('disk_used_gb', 10, 2)->nullable();
            $table->decimal('disk_total_gb', 10, 2)->nullable();
            $table->unsignedInteger('print_queue_depth')->default(0);
            $table->unsignedInteger('voice_queue_depth')->default(0);
            $table->unsignedInteger('print_count_today')->default(0);
            $table->unsignedInteger('print_failures_today')->default(0);
            $table->unsignedSmallInteger('network_latency_ms')->nullable();
            $table->unsignedSmallInteger('heartbeat_success_rate')->nullable()->comment('0-100 percent');
            $table->string('ws_state', 20)->nullable();
            $table->unsignedInteger('uptime_seconds')->nullable();
            $table->timestamp('recorded_at')->useCurrent();

            $table->foreign('agent_id')->references('id')->on('print_agents')->cascadeOnDelete();
            $table->index(['agent_id', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_telemetry');
    }
};
