<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('agent_commands', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agent_id');
            $table->string('command', 60)->index();
            $table->json('payload')->nullable();
            $table->string('status', 20)->default('pending')->index();
            $table->unsignedBigInteger('issued_by')->nullable();
            $table->timestamp('issued_at')->useCurrent();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->text('result')->nullable();
            $table->text('error')->nullable();

            $table->foreign('agent_id')->references('id')->on('print_agents')->cascadeOnDelete();
            $table->index(['agent_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_commands');
    }
};
