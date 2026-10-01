<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->createdBy(false);
            $table->string('name');
            $table->string('legal_name')->nullable();
            $table->string('slug')->unique();
            $table->string('domain')->nullable()->unique();
            $table->string('contact_name')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contact_phone', 30)->nullable();
            $table->json('settings')->nullable();
            $table->active();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('subscription_plans', function (Blueprint $table) {
            $table->id();
            $table->createdBy(false);
            $table->string('name');
            $table->string('code')->unique();
            $table->text('description')->nullable();
            $table->string('billing_cycle')->default('monthly');
            $table->decimal('price', 12, 2)->default(0);
            $table->string('currency', 3)->default('INR');
            $table->json('features')->nullable();
            $table->json('limits')->nullable();
            $table->active();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('tenant_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->createdBy(false);
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('subscription_plan_id')->nullable()->constrained('subscription_plans')->nullOnDelete();
            $table->string('status')->default('trial');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->json('overrides')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'status'], 'tenant_sub_tenant_status_idx');
        });

        Schema::create('tenant_feature_limits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('feature');
            $table->unsignedInteger('limit')->nullable();
            $table->unsignedInteger('used')->default(0);
            $table->timestamp('resets_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'feature'], 'tenant_feature_unique');
        });

        Schema::table('branches', function (Blueprint $table) {
            $table->foreignId('tenant_id')->nullable()->after('id')->constrained('tenants')->nullOnDelete();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreign('tenant_id')->references('id')->on('tenants')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tenant_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['tenant_id']);
        });

        Schema::dropIfExists('tenant_feature_limits');
        Schema::dropIfExists('tenant_subscriptions');
        Schema::dropIfExists('subscription_plans');
        Schema::dropIfExists('tenants');
    }
};
