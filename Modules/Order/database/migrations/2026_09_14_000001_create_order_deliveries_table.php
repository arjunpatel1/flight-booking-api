<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('order_deliveries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('branch_id')->index();
            $table->unsignedBigInteger('order_id')->unique();
            $table->string('mode', 32)->default('third_party');
            $table->string('provider', 64)->nullable();
            $table->string('partner_code', 80)->nullable();
            $table->string('partner_name', 120)->nullable();
            $table->string('external_delivery_id', 191)->nullable();
            $table->string('external_partner_id', 191)->nullable();
            $table->string('status', 48)->default('waiting_for_assignment');
            $table->string('assignment_status', 48)->default('pending');
            $table->decimal('pickup_latitude', 10, 7)->nullable();
            $table->decimal('pickup_longitude', 10, 7)->nullable();
            $table->decimal('dropoff_latitude', 10, 7)->nullable();
            $table->decimal('dropoff_longitude', 10, 7)->nullable();
            $table->decimal('distance_km', 9, 3)->nullable();
            $table->decimal('customer_delivery_fee', 18, 4)->default(0);
            $table->decimal('provider_quoted_cost', 18, 4)->nullable();
            $table->decimal('provider_final_cost', 18, 4)->nullable();
            $table->decimal('restaurant_contribution', 18, 4)->default(0);
            $table->decimal('platform_contribution', 18, 4)->default(0);
            $table->decimal('delivery_margin', 18, 4)->default(0);
            $table->unsignedSmallInteger('eta_minutes')->nullable();
            $table->string('rider_name', 120)->nullable();
            $table->string('rider_phone', 40)->nullable();
            $table->string('rider_vehicle', 120)->nullable();
            $table->text('tracking_url')->nullable();
            $table->string('failure_code', 80)->nullable();
            $table->text('failure_reason')->nullable();
            $table->unsignedSmallInteger('assignment_attempts')->default(0);
            $table->json('quote_history')->nullable();
            $table->uuid('assignment_token')->nullable();
            $table->uuid('provider_correlation_id')->nullable()->unique();
            $table->string('booking_phase', 40)->nullable();
            $table->string('provider_status', 80)->nullable();
            $table->timestamp('assignment_started_at')->nullable();
            $table->timestamp('booking_claimed_at')->nullable();
            $table->timestamp('booking_requested_at')->nullable();
            $table->timestamp('booking_completed_at')->nullable();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('rider_assigned_at')->nullable();
            $table->timestamp('picked_up_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_deliveries');
    }
};
