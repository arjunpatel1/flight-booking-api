<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tenant_payment_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('gateway_config_id')->constrained('tenant_payment_gateway_configs')->restrictOnDelete();
            $table->uuid('reference')->unique();
            $table->string('provider', 40);
            $table->string('provider_payment_id')->nullable();
            $table->string('idempotency_key', 120);
            $table->decimal('amount', 18, 3);
            $table->char('currency', 3);
            $table->string('status', 30)->default('creating');
            $table->text('intent_url')->nullable();
            $table->text('qr_data')->nullable();
            $table->string('transaction_reference')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->foreignId('payment_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'provider', 'idempotency_key'], 'tenant_provider_idempotency_unique');
            $table->unique(['gateway_config_id', 'provider_payment_id'], 'gateway_provider_payment_unique');
            $table->index(['tenant_id', 'branch_id', 'status', 'expires_at'], 'tenant_payment_session_status_index');
        });

        Schema::create('tenant_payment_gateway_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('gateway_config_id')->constrained('tenant_payment_gateway_configs')->cascadeOnDelete();
            $table->foreignId('payment_session_id')->nullable()->constrained('tenant_payment_sessions')->nullOnDelete();
            $table->string('provider_event_id', 120);
            $table->string('event_type', 80);
            $table->string('payload_hash', 64);
            $table->string('processing_status', 30)->default('received');
            $table->string('failure_code', 80)->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['gateway_config_id', 'provider_event_id'], 'gateway_event_replay_unique');
            $table->index(['tenant_id', 'processing_status', 'created_at'], 'tenant_gateway_event_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_payment_gateway_events');
        Schema::dropIfExists('tenant_payment_sessions');
    }
};
