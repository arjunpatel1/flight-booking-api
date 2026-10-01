<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('print_agent_pairings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code_hash', 64)->unique();
            $table->string('challenge_token_hash', 64)->unique();
            $table->string('device_public_id', 120)->nullable()->index();
            $table->string('device_name', 120)->nullable();
            $table->string('platform', 40)->default('windows');
            $table->string('agent_version', 40)->nullable();
            $table->string('status', 20)->default('pending')->index();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at')->index();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('claimed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->cascadeOnDelete();
            $table->foreignId('print_agent_id')->nullable()->constrained('print_agents')->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('print_agent_pairings');
    }
};
