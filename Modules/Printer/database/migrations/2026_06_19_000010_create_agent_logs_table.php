<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained('print_agents')->cascadeOnDelete();
            $table->string('level', 10);        // trace | debug | info | warning | error | fatal
            $table->text('message');
            $table->string('source_context', 255)->nullable();
            $table->text('exception')->nullable();
            $table->json('context')->nullable();    // structured properties from Serilog
            $table->timestamp('logged_at');         // when the event occurred on-agent
            $table->timestamp('uploaded_at')->useCurrent();

            // Hot-path: always filtered by agent_id; logged_at for pagination
            $table->index(['agent_id', 'logged_at'], 'idx_agent_logs_agent_time');
            // Level filter: error-only views
            $table->index(['agent_id', 'level', 'logged_at'], 'idx_agent_logs_agent_level_time');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_logs');
    }
};
