<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('saas_coupons', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name')->nullable();
            $table->string('type')->default('percentage');
            $table->decimal('value', 12, 2)->default(0);
            $table->unsignedInteger('free_months')->default(0);
            $table->unsignedInteger('trial_extension_days')->default(0);
            $table->foreignId('plan_upgrade_id')->nullable()->constrained('subscription_plans')->nullOnDelete();
            $table->boolean('lifetime')->default(false);
            $table->unsignedInteger('usage_limit')->nullable();
            $table->unsignedInteger('used_count')->default(0);
            $table->unsignedInteger('per_tenant_limit')->nullable();
            $table->unsignedInteger('per_email_limit')->nullable();
            $table->unsignedInteger('per_mobile_limit')->nullable();
            $table->foreignId('minimum_plan_id')->nullable()->constrained('subscription_plans')->nullOnDelete();
            $table->decimal('maximum_discount', 12, 2)->nullable();
            $table->string('source')->default('admin');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'expires_at']);
            $table->index(['source', 'created_at']);
        });

        Schema::create('saas_coupon_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('saas_coupon_id')->constrained('saas_coupons')->cascadeOnDelete();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->foreignId('tenant_subscription_id')->nullable()->constrained('tenant_subscriptions')->nullOnDelete();
            $table->foreignId('saas_billing_invoice_id')->nullable()->constrained('saas_billing_invoices')->nullOnDelete();
            $table->string('email')->nullable();
            $table->string('mobile', 30)->nullable();
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->json('metadata')->nullable();
            $table->timestamp('redeemed_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'created_at']);
            $table->index(['email', 'created_at']);
            $table->index(['mobile', 'created_at']);
        });

        Schema::create('saas_activation_keys', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name')->nullable();
            $table->foreignId('subscription_plan_id')->nullable()->constrained('subscription_plans')->nullOnDelete();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->string('partner')->nullable();
            $table->unsignedInteger('activation_limit')->default(1);
            $table->unsignedInteger('used_count')->default(0);
            $table->boolean('offline_allowed')->default(false);
            $table->string('status')->default('active');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'expires_at']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('saas_activation_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('saas_activation_key_id')->constrained('saas_activation_keys')->cascadeOnDelete();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->string('type');
            $table->string('status')->default('processed');
            $table->string('device_id')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('message')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'created_at']);
            $table->index(['saas_activation_key_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saas_activation_events');
        Schema::dropIfExists('saas_activation_keys');
        Schema::dropIfExists('saas_coupon_redemptions');
        Schema::dropIfExists('saas_coupons');
    }
};

