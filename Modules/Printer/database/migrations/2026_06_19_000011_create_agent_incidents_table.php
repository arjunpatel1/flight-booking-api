<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_incidents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained('print_agents')->cascadeOnDelete();
            $table->string('category', 50);
            $table->decimal('confidence', 3, 2)->default(0);
            $table->text('explanation');
            $table->string('technical_detail', 1000)->nullable();
            $table->json('fix_steps')->nullable();
            $table->integer('support_score')->default(0);
            $table->boolean('auto_fix_attempted')->default(false);
            $table->string('auto_fix_result', 20)->nullable();
            $table->text('auto_fix_detail')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('detected_at');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['agent_id', 'detected_at'], 'idx_agent_incidents_agent_time');
            $table->index(['agent_id', 'category', 'detected_at'], 'idx_agent_incidents_agent_cat_time');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_incidents');
    }
};
