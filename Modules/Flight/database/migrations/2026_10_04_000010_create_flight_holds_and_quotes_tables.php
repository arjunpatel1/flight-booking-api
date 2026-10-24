<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 12 — Price Check, Inventory Hold, Distributed Locking & Immutable Quotes
 *
 * Tables:
 *   flight_inventory_holds — Short-lived seat holds with status (ACTIVE, RELEASED, EXPIRED, CONVERTED, FAILED)
 *   flight_fare_quotes     — Immutable fare quotes with complete pricing breakdown and hold links
 *
 * IMPORTANT: Strictly isolated to Flight domain; never touches Restaurant tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Temporary Inventory Holds
        if (! Schema::hasTable('flight_inventory_holds')) {
            Schema::create('flight_inventory_holds', function (Blueprint $table) {
                $table->id();
                $table->string('hold_id', 40)->unique()->comment('External UUID reference for hold');
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->unsignedBigInteger('flight_inventory_id')->nullable()->index();
                $table->unsignedBigInteger('flight_fare_id')->nullable()->index();
                $table->string('supplier_code', 50)->default('INTERNAL')->index();
                $table->string('cabin_class', 30)->default('ECONOMY');
                $table->unsignedInteger('quantity')->default(1);
                $table->enum('status', [
                    'ACTIVE',
                    'RELEASED',
                    'EXPIRED',
                    'CONVERTED',
                    'FAILED',
                ])->default('ACTIVE')->index();
                $table->timestamp('expires_at')->index();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->string('reference', 100)->nullable()->index()->comment('Client reference / session token');
                $table->string('idempotency_key', 100)->nullable()->index();
                $table->unsignedBigInteger('reservation_id')->nullable()->comment('Linked flight_inventory_reservations row');
                $table->json('meta')->nullable()->comment('Snapshot of itinerary, segment and passenger selection');
                $table->timestamps();
                $table->softDeletes();

                $table->foreign('flight_inventory_id')
                    ->references('id')->on('flight_inventories')->onDelete('set null');
                $table->foreign('flight_fare_id')
                    ->references('id')->on('flight_fares')->onDelete('set null');
            });
        }

        // 2. Immutable Fare Quotes
        if (! Schema::hasTable('flight_fare_quotes')) {
            Schema::create('flight_fare_quotes', function (Blueprint $table) {
                $table->id();
                $table->string('quote_id', 40)->unique()->comment('External UUID reference for quote');
                $table->unsignedBigInteger('hold_id')->nullable()->index();
                $table->string('hold_reference', 40)->nullable()->index();
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->string('supplier_code', 50)->default('INTERNAL');
                $table->unsignedBigInteger('flight_inventory_id')->nullable()->index();
                $table->unsignedBigInteger('flight_fare_id')->nullable()->index();
                $table->string('currency', 3)->default('INR');
                $table->json('selected_itinerary')->comment('Immutable snapshot of flight, stops, airline, schedule');
                $table->json('fare_details')->comment('Fare family, baggage, refundability, changeability rules');
                $table->json('pricing_breakdown')->comment('Full pipeline: base, taxes, markups, gst, discount, steps');
                $table->decimal('customer_price', 12, 2);
                $table->json('search_context')->nullable()->comment('Origin, destination, dates, pax counts');
                $table->timestamp('expires_at')->index();
                $table->enum('status', [
                    'ACTIVE',
                    'ACCEPTED',
                    'EXPIRED',
                    'SUPERSEDED',
                ])->default('ACTIVE')->index();
                $table->string('idempotency_key', 100)->nullable()->index();
                $table->timestamps();

                $table->foreign('hold_id')
                    ->references('id')->on('flight_inventory_holds')->onDelete('set null');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('flight_fare_quotes');
        Schema::dropIfExists('flight_inventory_holds');
    }
};
