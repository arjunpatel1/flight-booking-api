<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('automation_executions')) {
            return;
        }

        Schema::create('automation_executions', function (Blueprint $table) {
            $table->id();
            $table->string('automation_id', 64)->index();
            $table->string('domain', 32)->nullable();
            $table->string('trigger', 64)->nullable();
            $table->string('action_key', 64)->nullable();
            $table->string('state', 24)->default('executing')->index();
            $table->string('idempotency_key', 64)->nullable()->unique();
            $table->foreignId('executor_id')->nullable();
            $table->foreignId('branch_id')->nullable()->index();
            $table->string('device_id', 120)->nullable();
            $table->json('result')->nullable();
            $table->boolean('rollback_available')->default(false);
            $table->json('rollback_payload')->nullable();
            $table->timestamp('rolled_back_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'state']);
            $table->index(['automation_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_executions');
    }
};
