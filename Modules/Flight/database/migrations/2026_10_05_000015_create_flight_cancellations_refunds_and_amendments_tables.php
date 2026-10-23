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
        // 1. Add Servicing Columns to Flight Bookings
        if (Schema::hasTable('flight_bookings')) {
            Schema::table('flight_bookings', function (Blueprint $table) {
                if (! Schema::hasColumn('flight_bookings', 'cancellation_fee')) {
                    $table->decimal('cancellation_fee', 12, 2)->nullable()->after('cancellation_reason');
                }
                if (! Schema::hasColumn('flight_bookings', 'refunded_amount')) {
                    $table->decimal('refunded_amount', 12, 2)->default(0.00)->after('cancellation_fee');
                }
                if (! Schema::hasColumn('flight_bookings', 'rescheduled_at')) {
                    $table->timestamp('rescheduled_at')->nullable()->after('cancelled_at');
                }
                if (! Schema::hasColumn('flight_bookings', 'reschedule_count')) {
                    $table->unsignedInteger('reschedule_count')->default(0)->after('rescheduled_at');
                }
            });
        }

        // 2. Flight Refunds Table
        if (! Schema::hasTable('flight_refunds')) {
            Schema::create('flight_refunds', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->nullable();
                $table->unsignedBigInteger('flight_booking_id');
                $table->unsignedBigInteger('flight_payment_id')->nullable();
                $table->string('refund_reference', 64)->unique();
                $table->string('supplier_refund_reference', 64)->nullable();
                $table->string('refund_type', 32)->default('CANCELLATION'); // CANCELLATION, RESCHEDULE_DIFFERENCE, PARTIAL
                $table->string('refund_method', 32)->default('ORIGINAL_PAYMENT'); // WALLET, GATEWAY, ORIGINAL_PAYMENT
                $table->decimal('original_amount', 12, 2);
                $table->decimal('cancellation_penalty', 12, 2)->default(0.00);
                $table->decimal('refund_amount', 12, 2);
                $table->string('currency', 3)->default('INR');
                $table->string('status', 32)->default('PENDING'); // PENDING, PROCESSED, FAILED, REJECTED
                $table->string('gateway_refund_id', 128)->nullable();
                $table->unsignedBigInteger('wallet_transaction_id')->nullable();
                $table->text('reason')->nullable();
                $table->text('failure_reason')->nullable();
                $table->json('audit_meta')->nullable();
                $table->timestamp('processed_at')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->foreign('flight_booking_id')->references('id')->on('flight_bookings')->onDelete('cascade');
                $table->index(['tenant_id', 'flight_booking_id'], 'idx_refund_tenant_booking');
                $table->index(['tenant_id', 'status'], 'idx_refund_tenant_status');
            });
        }

        // 3. Flight Booking Amendments Table (for Rescheduling & Changes Audit)
        if (! Schema::hasTable('flight_booking_amendments')) {
            Schema::create('flight_booking_amendments', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->nullable();
                $table->unsignedBigInteger('flight_booking_id');
                $table->string('amendment_reference', 64)->unique();
                $table->string('amendment_type', 32)->default('RESCHEDULE'); // RESCHEDULE, CANCELLATION, CORRECTION
                $table->unsignedBigInteger('previous_flight_inventory_id')->nullable();
                $table->unsignedBigInteger('new_flight_inventory_id')->nullable();
                $table->json('previous_itinerary')->nullable();
                $table->json('new_itinerary')->nullable();
                $table->decimal('previous_fare', 12, 2)->default(0.00);
                $table->decimal('new_fare', 12, 2)->default(0.00);
                $table->decimal('fare_difference', 12, 2)->default(0.00);
                $table->decimal('reschedule_fee', 12, 2)->default(0.00);
                $table->decimal('total_additional_charge', 12, 2)->default(0.00);
                $table->string('currency', 3)->default('INR');
                $table->string('status', 32)->default('COMPLETED'); // QUOTED, COMPLETED, CANCELLED
                $table->string('previous_pnr', 32)->nullable();
                $table->string('new_pnr', 32)->nullable();
                $table->text('reason')->nullable();
                $table->json('audit_trail')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->foreign('flight_booking_id')->references('id')->on('flight_bookings')->onDelete('cascade');
                $table->index(['tenant_id', 'flight_booking_id'], 'idx_amend_tenant_booking');
                $table->index(['tenant_id', 'amendment_type'], 'idx_amend_tenant_type');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('flight_booking_amendments');
        Schema::dropIfExists('flight_refunds');

        if (Schema::hasTable('flight_bookings')) {
            Schema::table('flight_bookings', function (Blueprint $table) {
                $table->dropColumn(['cancellation_fee', 'refunded_amount', 'rescheduled_at', 'reschedule_count']);
            });
        }
    }
};
