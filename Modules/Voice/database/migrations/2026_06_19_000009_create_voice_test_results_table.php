<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voice_test_results', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('agent_id')->nullable();
            $table->string('test_type', 30);         // speaker|tts|pipeline|custom
            $table->string('status', 20);             // success|failed|pending
            $table->text('message')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('device_used', 255)->nullable();
            $table->text('error')->nullable();
            $table->string('triggered_by', 20)->default('admin'); // admin|remote_command|scheduled
            $table->timestamp('created_at')->useCurrent();

            $table->index(['branch_id', 'created_at']);
            $table->index(['agent_id', 'created_at']);
            $table->index(['test_type', 'created_at']);

            $table->foreign('branch_id')->references('id')->on('branches')->onDelete('cascade');
            // Note: agent_id foreign key removed to avoid cross-module migration dependency issues
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voice_test_results');
    }
};
