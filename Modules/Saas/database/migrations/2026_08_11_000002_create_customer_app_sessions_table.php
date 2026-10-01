<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_app_sessions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('customer_app_registration_id')
                ->constrained('customer_app_registrations')->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('installation_id');
            $table->char('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['customer_app_registration_id', 'installation_id'],
                'customer_app_sessions_registration_installation_unique'
            );
            $table->index(['tenant_id', 'expires_at', 'revoked_at'], 'customer_app_sessions_tenant_state_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_app_sessions');
    }
};
