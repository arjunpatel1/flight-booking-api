<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations for Phase 10: Subscription + Quotas + Overage.
     */
    public function up(): void
    {
        // 1. Extend tenant_feature_limits with overage columns if missing
        if (Schema::hasTable('tenant_feature_limits')) {
            Schema::table('tenant_feature_limits', function (Blueprint $table) {
                if (! Schema::hasColumn('tenant_feature_limits', 'overage_allowed')) {
                    $table->boolean('overage_allowed')->default(false)->after('used');
                }
                if (! Schema::hasColumn('tenant_feature_limits', 'overage_unit_price')) {
                    $table->decimal('overage_unit_price', 10, 4)->default(0.0000)->after('overage_allowed');
                }
            });
        }

        // 2. Flight Subscription Overages Ledger
        if (! Schema::hasTable('flight_subscription_overages')) {
            Schema::create('flight_subscription_overages', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->index();
                $table->string('metric', 50)->index();
                $table->integer('units');
                $table->decimal('unit_price', 10, 4)->default(0.0000);
                $table->decimal('total_price', 12, 2)->default(0.00);
                $table->string('reference', 100)->nullable()->index();
                $table->string('actor', 100)->nullable();
                $table->text('description')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->index(['tenant_id', 'metric', 'created_at'], 'idx_fso_tenant_metric');
            });
        }

        // 3. Flight Usage Audit Logs
        if (! Schema::hasTable('flight_subscription_usage_logs')) {
            Schema::create('flight_subscription_usage_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->index();
                $table->string('metric', 50)->index();
                $table->integer('units');
                $table->integer('current_used');
                $table->integer('quota_limit')->nullable();
                $table->boolean('is_overage')->default(false);
                $table->string('reference', 100)->nullable();
                $table->string('actor', 100)->nullable();
                $table->timestamps();

                $table->index(['tenant_id', 'created_at'], 'idx_fsul_tenant_created');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('flight_subscription_usage_logs');
        Schema::dropIfExists('flight_subscription_overages');

        if (Schema::hasTable('tenant_feature_limits')) {
            Schema::table('tenant_feature_limits', function (Blueprint $table) {
                if (Schema::hasColumn('tenant_feature_limits', 'overage_unit_price')) {
                    $table->dropColumn('overage_unit_price');
                }
                if (Schema::hasColumn('tenant_feature_limits', 'overage_allowed')) {
                    $table->dropColumn('overage_allowed');
                }
            });
        }
    }
};
