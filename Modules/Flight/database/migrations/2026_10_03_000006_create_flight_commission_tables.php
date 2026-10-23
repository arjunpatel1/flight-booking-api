<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Phase 7: Commission Engine + Commission Rules
     * Supports configurable commission tiers: Super Admin -> Tenant -> Distributor -> Agent.
     */
    public function up(): void
    {
        if (! Schema::hasTable('flight_commission_rules')) {
            Schema::create('flight_commission_rules', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->string('name');
                $table->string('code')->nullable();
                $table->text('description')->nullable();

                // Recipient tier in hierarchy
                $table->string('recipient_tier', 30)->default('TENANT')->index();
                $table->unsignedBigInteger('recipient_id')->nullable()->index();

                // Matching criteria (wildcard when null)
                $table->unsignedBigInteger('flight_supplier_id')->nullable()->index();
                $table->unsignedBigInteger('airline_id')->nullable()->index();
                $table->string('airline_code', 10)->nullable()->index();
                $table->unsignedBigInteger('origin_airport_id')->nullable()->index();
                $table->unsignedBigInteger('destination_airport_id')->nullable()->index();
                $table->string('cabin_class', 30)->nullable()->index();
                $table->unsignedBigInteger('fare_family_id')->nullable()->index();
                $table->string('flight_number', 20)->nullable();

                // Calculation configuration
                $table->string('calculation_type', 20)->default('PERCENTAGE'); // PERCENTAGE, FIXED, HYBRID
                $table->string('calculation_basis', 20)->default('BASE_FARE');  // BASE_FARE, TOTAL_FARE, PER_BOOKING, PER_PASSENGER
                $table->decimal('percentage', 8, 4)->default(0.0000);
                $table->decimal('fixed_amount', 12, 2)->default(0.00);
                $table->decimal('min_commission', 12, 2)->nullable();
                $table->decimal('max_commission', 12, 2)->nullable();
                $table->string('currency', 3)->default('INR');

                // Precedence & Lifecycle
                $table->integer('priority')->default(10)->index();
                $table->boolean('override_parent')->default(false);
                $table->boolean('is_active')->default(true)->index();
                $table->dateTime('effective_from')->nullable()->index();
                $table->dateTime('effective_to')->nullable()->index();

                // Audit metadata
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['recipient_tier', 'is_active', 'priority'], 'idx_fcr_tier_active_prio');
            });
        }

        if (! Schema::hasTable('flight_commission_logs')) {
            Schema::create('flight_commission_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->unsignedBigInteger('flight_commission_rule_id')->nullable()->index();
                $table->string('booking_reference', 50)->nullable()->index();

                $table->string('recipient_tier', 30)->index();
                $table->unsignedBigInteger('recipient_id')->nullable()->index();

                $table->decimal('base_amount', 12, 2)->default(0.00);
                $table->decimal('commission_amount', 12, 2)->default(0.00);
                $table->decimal('rate_percentage', 8, 4)->nullable();
                $table->decimal('fixed_amount', 12, 2)->nullable();
                $table->string('currency', 3)->default('INR');

                $table->json('breakdown')->nullable();
                $table->json('calculation_context')->nullable();

                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('flight_commission_logs');
        Schema::dropIfExists('flight_commission_rules');
    }
};
