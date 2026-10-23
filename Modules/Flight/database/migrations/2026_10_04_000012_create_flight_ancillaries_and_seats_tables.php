<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 14 — Flight Ancillaries, Seat Map, Extra Baggage, Meals & Seat Locks
 *
 * Tables:
 *   flight_ancillaries         — Catalogue of baggage, meals, lounge, and special services
 *   flight_seat_locks          — Distributed inventory locking for seats (concurrency safety & TTL)
 *   flight_booking_ancillaries — Passenger-associated purchased ancillaries & seats with price adjustments
 *
 * IMPORTANT: Strictly isolated to Flight domain; never touches Restaurant tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Ancillaries Catalog (Baggage, Meals, Special Services)
        if (! Schema::hasTable('flight_ancillaries')) {
            Schema::create('flight_ancillaries', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->unsignedBigInteger('flight_inventory_id')->nullable()->index();

                $table->enum('category', [
                    'SEAT',
                    'BAGGAGE',
                    'MEAL',
                    'LOUNGE',
                    'SPECIAL_ASSISTANCE',
                    'PRIORITY_BOARDING',
                ])->default('BAGGAGE')->index();

                $table->string('code', 50)->index();
                $table->string('name', 100);
                $table->text('description')->nullable();
                $table->decimal('price', 10, 2)->default(0.00);
                $table->string('currency', 3)->default('INR');
                $table->unsignedSmallInteger('max_quantity_per_pax')->default(1);
                $table->integer('available_quantity')->default(-1)->comment('-1 = unlimited/capacity based');
                $table->string('supplier_code', 50)->default('INTERNAL')->index();
                $table->string('status', 20)->default('ACTIVE')->index();
                $table->json('meta')->nullable()->comment('Weight, dietary tags, baggage dimensions');
                $table->timestamps();
                $table->softDeletes();
            });
        }

        // 2. Seat Inventory Locking (Concurrency Safety, Holds & TTL)
        if (! Schema::hasTable('flight_seat_locks')) {
            Schema::create('flight_seat_locks', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->unsignedBigInteger('flight_inventory_id')->index();
                $table->string('seat_number', 10)->index();
                $table->string('lock_token', 64)->unique()->index();
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->unsignedBigInteger('booking_id')->nullable()->index();
                $table->unsignedBigInteger('passenger_id')->nullable()->index();

                $table->enum('status', [
                    'LOCKED',
                    'CONFIRMED',
                    'RELEASED',
                    'EXPIRED',
                ])->default('LOCKED')->index();

                $table->timestamp('expires_at')->index();
                $table->timestamps();

                $table->foreign('flight_inventory_id')
                    ->references('id')->on('flight_inventories')->onDelete('cascade');
            });
        }

        // 3. Booking-Passenger Associated Ancillaries
        if (! Schema::hasTable('flight_booking_ancillaries')) {
            Schema::create('flight_booking_ancillaries', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->unsignedBigInteger('booking_id')->index();
                $table->unsignedBigInteger('passenger_id')->index();
                $table->unsignedBigInteger('ancillary_id')->nullable()->index();

                $table->enum('category', [
                    'SEAT',
                    'BAGGAGE',
                    'MEAL',
                    'LOUNGE',
                    'SPECIAL_ASSISTANCE',
                    'PRIORITY_BOARDING',
                ])->index();

                $table->string('code', 50);
                $table->string('name', 100);
                $table->text('description')->nullable();
                $table->decimal('price', 10, 2)->default(0.00);
                $table->string('currency', 3)->default('INR');
                $table->unsignedSmallInteger('quantity')->default(1);
                $table->string('seat_number', 10)->nullable()->index();
                $table->string('supplier_code', 50)->default('INTERNAL');
                $table->string('status', 20)->default('CONFIRMED')->index();
                $table->json('meta')->nullable();
                $table->timestamps();

                $table->foreign('booking_id')
                    ->references('id')->on('flight_bookings')->onDelete('cascade');
                $table->foreign('passenger_id')
                    ->references('id')->on('flight_booking_passengers')->onDelete('cascade');
                $table->foreign('ancillary_id')
                    ->references('id')->on('flight_ancillaries')->onDelete('set null');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('flight_booking_ancillaries');
        Schema::dropIfExists('flight_seat_locks');
        Schema::dropIfExists('flight_ancillaries');
    }
};
