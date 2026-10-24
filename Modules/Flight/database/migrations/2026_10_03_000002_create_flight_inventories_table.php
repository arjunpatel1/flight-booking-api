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
        // 1. Add country fields to flight_airlines if missing
        Schema::table('flight_airlines', function (Blueprint $table) {
            if (! Schema::hasColumn('flight_airlines', 'country')) {
                $table->string('country', 100)->nullable()->after('name');
            }
            if (! Schema::hasColumn('flight_airlines', 'country_code')) {
                $table->string('country_code', 3)->nullable()->after('country');
            }
        });

        // 2. Add registration & aircraft_type to flight_aircraft if missing
        Schema::table('flight_aircraft', function (Blueprint $table) {
            if (! Schema::hasColumn('flight_aircraft', 'registration')) {
                $table->string('registration', 30)->nullable()->after('model');
            }
            if (! Schema::hasColumn('flight_aircraft', 'aircraft_type')) {
                $table->string('aircraft_type', 50)->nullable()->after('iata_code');
            }
        });

        // 3. Create flight_inventories table
        if (! Schema::hasTable('flight_inventories')) {
            Schema::create('flight_inventories', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->string('inventory_id', 64);
                $table->unsignedBigInteger('airline_id');
                $table->string('flight_number', 20);
                $table->unsignedBigInteger('origin_airport_id');
                $table->unsignedBigInteger('destination_airport_id');
                $table->time('departure_time');
                $table->time('arrival_time');
                $table->json('operating_days')->nullable();
                $table->unsignedBigInteger('aircraft_id')->nullable();
                $table->enum('cabin_class', ['ECONOMY', 'PREMIUM_ECONOMY', 'BUSINESS', 'FIRST'])->default('ECONOMY');
                $table->unsignedInteger('total_seats')->default(0);
                $table->unsignedInteger('available_seats')->default(0);
                $table->json('baggage_policy')->nullable();
                $table->json('seat_map_configuration')->nullable();
                $table->boolean('is_refundable')->default(true);
                $table->text('cancellation_policy')->nullable();
                $table->text('reschedule_policy')->nullable();
                $table->enum('status', ['active', 'inactive', 'suspended', 'cancelled'])->default('active');
                $table->decimal('base_price', 14, 4)->default(0.0000);
                $table->timestamps();

                $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
                $table->foreign('airline_id')->references('id')->on('flight_airlines')->onDelete('restrict');
                $table->foreign('origin_airport_id')->references('id')->on('flight_airports')->onDelete('restrict');
                $table->foreign('destination_airport_id')->references('id')->on('flight_airports')->onDelete('restrict');
                $table->foreign('aircraft_id')->references('id')->on('flight_aircraft')->onDelete('set null');

                $table->unique(['tenant_id', 'inventory_id'], 'uk_tenant_inventory');
                $table->index(['tenant_id', 'airline_id', 'flight_number'], 'idx_tenant_flight');
                $table->index(['tenant_id', 'origin_airport_id', 'destination_airport_id', 'cabin_class'], 'idx_route_cabin');
                $table->index(['tenant_id', 'status']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('flight_inventories');

        Schema::table('flight_aircraft', function (Blueprint $table) {
            if (Schema::hasColumn('flight_aircraft', 'registration')) {
                $table->dropColumn('registration');
            }
            if (Schema::hasColumn('flight_aircraft', 'aircraft_type')) {
                $table->dropColumn('aircraft_type');
            }
        });

        Schema::table('flight_airlines', function (Blueprint $table) {
            if (Schema::hasColumn('flight_airlines', 'country')) {
                $table->dropColumn('country');
            }
            if (Schema::hasColumn('flight_airlines', 'country_code')) {
                $table->dropColumn('country_code');
            }
        });
    }
};
