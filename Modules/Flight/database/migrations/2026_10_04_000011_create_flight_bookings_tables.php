<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 13 — Flight Booking & Passenger Management
 *
 * Tables:
 *   flight_bookings          — Complete booking lifecycle, state machine, snapshots, amounts, contact
 *   flight_booking_passengers — Passenger records (Adult, Child, Infant), passport & preferences
 *
 * IMPORTANT: Strictly isolated to Flight domain; never touches Restaurant tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('flight_bookings')) {
            Schema::create('flight_bookings', function (Blueprint $table) {
                $table->id();
                $table->string('booking_reference', 30)->unique()->comment('Human-readable booking reference e.g. BKNG-XXXXXXXX');
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->unsignedBigInteger('user_id')->nullable()->index();

                // Linkages
                $table->unsignedBigInteger('quote_id')->nullable()->index();
                $table->string('quote_reference', 40)->nullable()->index();
                $table->unsignedBigInteger('hold_id')->nullable()->index();
                $table->string('hold_reference', 40)->nullable()->index();
                $table->unsignedBigInteger('flight_inventory_id')->nullable()->index();
                $table->unsignedBigInteger('flight_fare_id')->nullable()->index();
                $table->string('supplier_code', 50)->default('INTERNAL');

                // Snapshots
                $table->json('itinerary')->nullable()->comment('Snapshot of flight, stops, airline, departure, arrival');
                $table->json('fare_snapshot')->nullable()->comment('Fare family, baggage, refundability, changeability rules');
                $table->json('pricing_breakdown')->nullable()->comment('Base fare, taxes, fees, discounts breakdown');

                // Financials
                $table->decimal('total_amount', 12, 2)->default(0.00);
                $table->string('currency', 3)->default('INR');

                // Controlled State Machine
                $table->enum('booking_status', [
                    'DRAFT',
                    'QUOTED',
                    'PAYMENT_PENDING',
                    'CONFIRMED',
                    'CANCELLED',
                    'FAILED',
                    'COMPLETED',
                ])->default('DRAFT')->index();

                $table->enum('payment_status', [
                    'PENDING',
                    'PAID',
                    'REFUNDED',
                    'FAILED',
                ])->default('PENDING')->index();

                $table->string('payment_reference', 100)->nullable()->index();

                // Pax Counts
                $table->unsignedSmallInteger('pax_count_adult')->default(1);
                $table->unsignedSmallInteger('pax_count_child')->default(0);
                $table->unsignedSmallInteger('pax_count_infant')->default(0);

                // Primary Contact
                $table->string('contact_name', 150)->nullable();
                $table->string('contact_email', 150)->nullable()->index();
                $table->string('contact_phone', 50)->nullable();

                // Metadata & Audit
                $table->string('idempotency_key', 100)->nullable()->index();
                $table->text('cancellation_reason')->nullable();
                $table->json('meta')->nullable();

                $table->timestamp('confirmed_at')->nullable();
                $table->timestamp('cancelled_at')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->foreign('flight_inventory_id')
                    ->references('id')->on('flight_inventories')->onDelete('set null');
                $table->foreign('flight_fare_id')
                    ->references('id')->on('flight_fares')->onDelete('set null');
            });
        }

        if (! Schema::hasTable('flight_booking_passengers')) {
            Schema::create('flight_booking_passengers', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('booking_id')->index();
                $table->unsignedBigInteger('tenant_id')->nullable()->index();

                $table->enum('passenger_type', [
                    'ADULT',
                    'CHILD',
                    'INFANT',
                ])->default('ADULT')->index();

                $table->string('title', 20)->nullable();
                $table->string('first_name', 100);
                $table->string('last_name', 100);
                $table->date('date_of_birth')->nullable();
                $table->string('gender', 20)->nullable();
                $table->string('nationality', 10)->nullable();

                // Passport / International Travel
                $table->boolean('is_international')->default(false);
                $table->string('passport_number', 50)->nullable();
                $table->date('passport_expiry')->nullable();
                $table->string('passport_country', 10)->nullable();

                // Preferences
                $table->string('seat_preference', 50)->nullable();
                $table->string('meal_preference', 50)->nullable();
                $table->string('frequent_flyer_number', 50)->nullable();

                // Optional direct contact per passenger
                $table->string('contact_email', 150)->nullable();
                $table->string('contact_phone', 50)->nullable();

                $table->json('meta')->nullable();
                $table->timestamps();

                $table->foreign('booking_id')
                    ->references('id')->on('flight_bookings')->onDelete('cascade');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('flight_booking_passengers');
        Schema::dropIfExists('flight_bookings');
    }
};
