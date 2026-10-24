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
        // 1. Airports Reference Table
        if (! Schema::hasTable('flight_airports')) {
            Schema::create('flight_airports', function (Blueprint $table) {
                $table->id();
                $table->string('iata_code', 3)->unique();
                $table->string('icao_code', 4)->nullable();
                $table->string('name', 191);
                $table->string('city', 100);
                $table->string('city_code', 3);
                $table->string('country', 100);
                $table->string('country_code', 2);
                $table->decimal('latitude', 10, 7)->default(0);
                $table->decimal('longitude', 10, 7)->default(0);
                $table->string('timezone', 50)->default('UTC');
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->index('city_code');
                $table->index('country_code');
                $table->index('is_active');
            });
        }

        // 2. Airlines Reference Table
        if (! Schema::hasTable('flight_airlines')) {
            Schema::create('flight_airlines', function (Blueprint $table) {
                $table->id();
                $table->string('iata_code', 2)->unique();
                $table->string('icao_code', 3)->nullable();
                $table->string('name', 100);
                $table->string('logo_url', 255)->nullable();
                $table->enum('alliance', ['star_alliance', 'skyteam', 'oneworld', 'none'])->default('none');
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->index('is_active');
            });
        }

        // 3. Aircraft Equipment Table
        if (! Schema::hasTable('flight_aircraft')) {
            Schema::create('flight_aircraft', function (Blueprint $table) {
                $table->id();
                $table->string('iata_code', 3)->unique();
                $table->string('model', 100);
                $table->string('manufacturer', 100);
                $table->unsignedInteger('total_seats')->default(180);
                $table->json('seat_configuration')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        // 4. Tenant Flight Routes
        if (! Schema::hasTable('flight_routes')) {
            Schema::create('flight_routes', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('origin_airport_id');
                $table->unsignedBigInteger('destination_airport_id');
                $table->unsignedInteger('distance_km')->default(0);
                $table->unsignedInteger('flight_duration_minutes')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('restrict');
                $table->foreign('origin_airport_id')->references('id')->on('flight_airports')->onDelete('restrict');
                $table->foreign('destination_airport_id')->references('id')->on('flight_airports')->onDelete('restrict');
                $table->unique(['tenant_id', 'origin_airport_id', 'destination_airport_id'], 'uk_tenant_route');
                $table->index(['tenant_id', 'is_active']);
            });
        }

        // 5. Tenant Flight Schedules
        if (! Schema::hasTable('flight_schedules')) {
            Schema::create('flight_schedules', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('route_id');
                $table->unsignedBigInteger('airline_id');
                $table->string('flight_number', 10);
                $table->time('departure_time');
                $table->time('arrival_time');
                $table->tinyInteger('day_offset')->default(0);
                $table->json('days_of_week');
                $table->date('valid_from');
                $table->date('valid_until');
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('restrict');
                $table->foreign('route_id')->references('id')->on('flight_routes')->onDelete('restrict');
                $table->foreign('airline_id')->references('id')->on('flight_airlines')->onDelete('restrict');
                $table->index(['tenant_id', 'flight_number']);
                $table->index(['tenant_id', 'is_active']);
            });
        }

        // 6. Concrete Flight Instances
        if (! Schema::hasTable('flight_instances')) {
            Schema::create('flight_instances', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('schedule_id');
                $table->unsignedBigInteger('aircraft_id');
                $table->string('flight_number', 10);
                $table->dateTime('departure_datetime');
                $table->dateTime('arrival_datetime');
                $table->enum('status', ['scheduled', 'boarding', 'departed', 'in_air', 'landed', 'delayed', 'cancelled'])->default('scheduled');
                $table->string('terminal_departure', 20)->nullable();
                $table->string('terminal_arrival', 20)->nullable();
                $table->string('gate', 20)->nullable();
                $table->timestamps();

                $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('restrict');
                $table->foreign('schedule_id')->references('id')->on('flight_schedules')->onDelete('restrict');
                $table->foreign('aircraft_id')->references('id')->on('flight_aircraft')->onDelete('restrict');
                $table->index(['tenant_id', 'departure_datetime']);
                $table->index(['tenant_id', 'status']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('flight_fares');
        Schema::dropIfExists('flight_instances');
        Schema::dropIfExists('flight_schedules');
        Schema::dropIfExists('flight_routes');
        Schema::dropIfExists('flight_aircraft');
        Schema::dropIfExists('flight_airlines');
        Schema::dropIfExists('flight_airports');
    }
};
