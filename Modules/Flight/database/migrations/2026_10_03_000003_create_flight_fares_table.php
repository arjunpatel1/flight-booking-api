<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Fare Families
        if (! Schema::hasTable('flight_fare_families')) {
            Schema::create('flight_fare_families', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->nullable();
                $table->string('code', 50); // BASIC, STANDARD, FLEX, BUSINESS, FIRST
                $table->string('name', 100);
                $table->enum('cabin_class', ['ECONOMY', 'PREMIUM_ECONOMY', 'BUSINESS', 'FIRST'])->default('ECONOMY');
                $table->text('description')->nullable();
                $table->json('features')->nullable();
                $table->json('baggage_allowance')->nullable();
                $table->json('changeability')->nullable();
                $table->json('refundability')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
                $table->index(['tenant_id', 'code'], 'idx_tenant_fare_family_code');
                $table->index(['tenant_id', 'cabin_class'], 'idx_tenant_cabin_class');
            });
        }

        // 2. Flight Fares
        if (Schema::hasTable('flight_fares') && ! Schema::hasColumn('flight_fares', 'flight_inventory_id')) {
            Schema::dropIfExists('flight_fare_rules');
            Schema::dropIfExists('flight_fares');
        }

        if (! Schema::hasTable('flight_fares')) {
            Schema::create('flight_fares', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('flight_inventory_id');
                $table->unsignedBigInteger('fare_family_id')->nullable();
                $table->string('fare_family_code', 50)->default('STANDARD');
                $table->string('fare_basis_code', 50)->nullable();
                $table->enum('cabin_class', ['ECONOMY', 'PREMIUM_ECONOMY', 'BUSINESS', 'FIRST'])->default('ECONOMY');
                $table->string('currency', 3)->default('USD');
                $table->string('supplier_code', 50)->default('DIRECT');

                // Pricing Pipeline Fields
                $table->decimal('supplier_fare', 14, 4)->default(0.0000);
                $table->decimal('pricing_rules_adjustment', 14, 4)->default(0.0000);
                $table->decimal('base_fare', 14, 4)->default(0.0000);
                $table->decimal('taxes', 14, 4)->default(0.0000);
                $table->decimal('supplier_fees', 14, 4)->default(0.0000);
                $table->decimal('platform_markup', 14, 4)->default(0.0000);
                $table->decimal('tenant_markup', 14, 4)->default(0.0000);
                $table->decimal('client_markup', 14, 4)->default(0.0000);
                $table->decimal('convenience_fee', 14, 4)->default(0.0000);
                $table->decimal('gst', 14, 4)->default(0.0000);
                $table->decimal('discount', 14, 4)->default(0.0000);
                $table->decimal('final_customer_price', 14, 4)->default(0.0000);

                // Conditions & Availability
                $table->json('baggage')->nullable();
                $table->boolean('is_refundable')->default(false);
                $table->boolean('is_changeable')->default(true);
                $table->unsignedInteger('total_seats')->default(0);
                $table->unsignedInteger('available_seats')->default(0);
                $table->timestamp('valid_from')->nullable();
                $table->timestamp('valid_until')->nullable();
                $table->enum('status', ['active', 'expired', 'sold_out', 'suspended'])->default('active');
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
                $table->foreign('flight_inventory_id')->references('id')->on('flight_inventories')->onDelete('cascade');
                $table->foreign('fare_family_id')->references('id')->on('flight_fare_families')->onDelete('set null');

                $table->index(['tenant_id', 'flight_inventory_id'], 'idx_tenant_inventory_fare');
                $table->index(['tenant_id', 'fare_family_code'], 'idx_tenant_fare_family');
                $table->index(['tenant_id', 'status'], 'idx_tenant_fare_status');
            });
        }

        // 3. Flight Fare Rules
        if (! Schema::hasTable('flight_fare_rules')) {
            Schema::create('flight_fare_rules', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('flight_fare_id');
                $table->text('cancellation_policy')->nullable();
                $table->text('refund_policy')->nullable();
                $table->text('reschedule_policy')->nullable();
                $table->text('baggage_policy')->nullable();
                $table->unsignedInteger('advance_purchase_days')->default(0);
                $table->unsignedInteger('min_stay_days')->nullable();
                $table->unsignedInteger('max_stay_days')->nullable();
                $table->json('rule_details')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
                $table->foreign('flight_fare_id')->references('id')->on('flight_fares')->onDelete('cascade');
                $table->index(['tenant_id', 'flight_fare_id'], 'idx_tenant_fare_rule');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('flight_fare_rules');
        Schema::dropIfExists('flight_fares');
        Schema::dropIfExists('flight_fare_families');
    }
};
