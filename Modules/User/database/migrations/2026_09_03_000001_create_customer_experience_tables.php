<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_wallet_accounts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('customer_id');
            $table->char('currency', 3)->default('INR');
            $table->decimal('balance', 18, 4)->default(0);
            $table->timestamps();
            $table->unique(['tenant_id', 'customer_id', 'currency'], 'customer_wallet_owner_currency_unique');
        });

        Schema::create('customer_wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->uuid('reference')->unique();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('order_id')->nullable();
            $table->string('type', 30);
            $table->string('direction', 10);
            $table->decimal('amount', 18, 4);
            $table->decimal('balance_after', 18, 4);
            $table->char('currency', 3)->default('INR');
            $table->string('idempotency_key', 100)->nullable();
            $table->string('description', 255)->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'customer_id', 'created_at'], 'customer_wallet_ledger_owner_index');
            $table->unique(['tenant_id', 'idempotency_key'], 'customer_wallet_idempotency_unique');
        });

        Schema::create('customer_referral_codes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('referrer_customer_id');
            $table->string('code', 24);
            $table->unsignedInteger('reward_points')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'code']);
            $table->unique(['tenant_id', 'referrer_customer_id']);
        });

        Schema::create('customer_referrals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('referral_code_id');
            $table->unsignedBigInteger('referrer_customer_id');
            $table->unsignedBigInteger('referred_customer_id')->nullable();
            $table->unsignedBigInteger('qualifying_order_id')->nullable();
            $table->string('status', 20)->default('invited');
            $table->unsignedInteger('reward_points')->default(0);
            $table->timestamp('qualified_at')->nullable();
            $table->timestamp('rewarded_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'referred_customer_id'], 'customer_referral_referred_unique');
            $table->index(['tenant_id', 'referrer_customer_id', 'created_at'], 'customer_referral_owner_index');
        });

        Schema::create('customer_notifications', function (Blueprint $table) {
            $table->id();
            $table->uuid('reference')->unique();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('customer_id');
            $table->string('type', 50);
            $table->string('title', 160);
            $table->text('message');
            $table->string('action_url', 500)->nullable();
            $table->json('data')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'customer_id', 'read_at', 'created_at'], 'customer_notification_feed_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_notifications');
        Schema::dropIfExists('customer_referrals');
        Schema::dropIfExists('customer_referral_codes');
        Schema::dropIfExists('customer_wallet_transactions');
        Schema::dropIfExists('customer_wallet_accounts');
    }
};
