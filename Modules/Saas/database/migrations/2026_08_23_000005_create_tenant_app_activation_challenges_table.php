<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_app_activation_challenges', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->string('app', 40)->default('waiter_app');
            $table->char('token_hash', 64)->unique();
            $table->char('code_hash', 64)->unique();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->string('consumed_ip', 45)->nullable();
            $table->string('consumed_user_agent', 255)->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'app', 'expires_at']);
            $table->index(['tenant_id', 'consumed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_app_activation_challenges');
    }
};
