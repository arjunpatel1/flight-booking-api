<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\User\Models\User;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('pos_manager_approvals', function (Blueprint $table) {
            $table->id();
            $table->createdBy();
            $table->branch();
            $table->foreignIdFor(User::class, 'manager_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('pos_terminal_device_id')->nullable()->constrained('pos_terminal_devices')->nullOnDelete();
            $table->string('device_id')->nullable();
            $table->string('action');
            $table->string('resource_type')->nullable();
            $table->string('resource_id')->nullable();
            $table->string('approval_token')->unique();
            $table->string('status')->default('approved');
            $table->text('reason')->nullable();
            $table->json('payload')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'action', 'created_at']);
            $table->index(['manager_id', 'created_at']);
            $table->index(['device_id', 'created_at']);
            $table->index(['resource_type', 'resource_id']);
            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_manager_approvals');
    }
};
