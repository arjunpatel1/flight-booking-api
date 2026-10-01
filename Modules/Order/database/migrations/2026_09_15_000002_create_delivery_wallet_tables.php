<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('delivery_wallet_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->unique()->constrained('tenants')->cascadeOnDelete();
            $table->char('currency', 3);
            $table->decimal('available_balance', 18, 4)->default(0);
            $table->decimal('reserved_balance', 18, 4)->default(0);
            $table->decimal('low_balance_threshold', 18, 4)->default(0);
            $table->boolean('block_booking_when_insufficient')->default(true);
            $table->timestamps();
        });

        Schema::create('delivery_wallet_transactions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('wallet_account_id')->constrained('delivery_wallet_accounts')->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('order_delivery_id')->nullable()->constrained('order_deliveries')->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 32);
            $table->string('status', 24)->default('posted');
            $table->decimal('amount', 18, 4);
            $table->decimal('available_before', 18, 4);
            $table->decimal('available_after', 18, 4);
            $table->decimal('reserved_before', 18, 4);
            $table->decimal('reserved_after', 18, 4);
            $table->string('idempotency_key', 191);
            $table->string('reference', 191)->nullable();
            $table->string('reason', 500);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'idempotency_key'], 'delivery_wallet_tenant_idempotency_unique');
            $table->index(['tenant_id', 'created_at'], 'delivery_wallet_tenant_created_index');
            $table->index(['order_delivery_id', 'type'], 'delivery_wallet_delivery_type_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_wallet_transactions');
        Schema::dropIfExists('delivery_wallet_accounts');
    }
};
