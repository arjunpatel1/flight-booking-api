<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_push_devices', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->uuid('installation_id');
            $table->text('push_token');
            $table->char('token_hash', 64);
            $table->string('platform', 24)->nullable();
            $table->string('app_version', 40)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'user_id', 'installation_id'], 'customer_push_device_installation_unique');
            $table->unique('token_hash', 'customer_push_device_token_unique');
            $table->index(['tenant_id', 'user_id', 'revoked_at'], 'customer_push_device_recipient_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_push_devices');
    }
};
