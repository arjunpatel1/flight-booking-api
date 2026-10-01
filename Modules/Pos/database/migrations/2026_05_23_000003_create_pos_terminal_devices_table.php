<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('pos_terminal_devices', function (Blueprint $table) {
            $table->id();
            $table->createdBy();
            $table->branch();
            $table->foreignId('pos_register_id')->nullable()->constrained('pos_registers')->nullOnDelete();
            $table->foreignId('pos_session_id')->nullable()->constrained('pos_sessions')->nullOnDelete();
            $table->string('device_id')->unique();
            $table->string('name')->nullable();
            $table->string('status')->default('online');
            $table->string('app_version')->nullable();
            $table->string('platform')->nullable();
            $table->string('browser')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->unsignedInteger('local_queue_count')->default(0);
            $table->unsignedInteger('server_queue_count')->default(0);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('last_offline_at')->nullable();
            $table->timestamp('last_sync_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['branch_id', 'status', 'last_seen_at']);
            $table->index(['pos_register_id', 'status']);
            $table->index(['pos_session_id', 'status']);
            $table->index(['last_seen_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_terminal_devices');
    }
};
