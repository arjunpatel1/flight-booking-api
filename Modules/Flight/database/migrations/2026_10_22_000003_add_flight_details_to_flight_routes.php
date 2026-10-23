<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ROUTE_PAIR_UNIQUE = 'uk_tenant_route';

    public function up(): void
    {
        if (! Schema::hasTable('flight_routes')) {
            return;
        }

        Schema::table('flight_routes', function (Blueprint $table): void {
            if (Schema::hasIndex('flight_routes', self::ROUTE_PAIR_UNIQUE)) {
                // One tenant can operate several flights on the same airport pair.
                $table->dropUnique(self::ROUTE_PAIR_UNIQUE);
            }

            $columns = [
                'flight_number' => fn () => $table->string('flight_number', 20)->nullable(),
                'flight_date' => fn () => $table->date('flight_date')->nullable(),
                'flight_time' => fn () => $table->time('flight_time')->nullable(),
                'end_date' => fn () => $table->date('end_date')->nullable(),
                'end_time' => fn () => $table->time('end_time')->nullable(),
                'purchase_date' => fn () => $table->date('purchase_date')->nullable(),
                'pnr_number' => fn () => $table->string('pnr_number', 50)->nullable(),
                'departure_terminal' => fn () => $table->string('departure_terminal', 20)->nullable(),
                'arrival_terminal' => fn () => $table->string('arrival_terminal', 20)->nullable(),
                'adult_count' => fn () => $table->unsignedSmallInteger('adult_count')->default(1),
                'child_count' => fn () => $table->unsignedSmallInteger('child_count')->default(0),
                'total_seats' => fn () => $table->unsignedInteger('total_seats')->nullable(),
                'available_seats' => fn () => $table->unsignedInteger('available_seats')->nullable(),
                'consumed_seats' => fn () => $table->unsignedInteger('consumed_seats')->default(0),
                'total_adult_seats' => fn () => $table->unsignedInteger('total_adult_seats')->nullable(),
                'total_child_seats' => fn () => $table->unsignedInteger('total_child_seats')->nullable(),
                'available_adult_seats' => fn () => $table->unsignedInteger('available_adult_seats')->nullable(),
                'available_child_seats' => fn () => $table->unsignedInteger('available_child_seats')->nullable(),
                'consumed_adult_seats' => fn () => $table->unsignedInteger('consumed_adult_seats')->default(0),
                'consumed_child_seats' => fn () => $table->unsignedInteger('consumed_child_seats')->default(0),
                'total_seats_price' => fn () => $table->decimal('total_seats_price', 12, 2)->nullable(),
                'adult_price' => fn () => $table->decimal('adult_price', 12, 2)->default(0),
                'child_price' => fn () => $table->decimal('child_price', 12, 2)->default(0),
                'infant_price' => fn () => $table->decimal('infant_price', 12, 2)->default(0),
                'gst_percentage' => fn () => $table->decimal('gst_percentage', 6, 2)->default(0),
                'gst_amount' => fn () => $table->decimal('gst_amount', 12, 2)->default(0),
                'total_adult_price_with_gst' => fn () => $table->decimal('total_adult_price_with_gst', 12, 2)->nullable(),
                'total_child_price_with_gst' => fn () => $table->decimal('total_child_price_with_gst', 12, 2)->nullable(),
                'total_infant_price_with_gst' => fn () => $table->decimal('total_infant_price_with_gst', 12, 2)->nullable(),
                'fare_families' => fn () => $table->json('fare_families')->nullable(),
                'fare_family_prices' => fn () => $table->json('fare_family_prices')->nullable(),
                'fare_family_pax_prices' => fn () => $table->json('fare_family_pax_prices')->nullable(),
                'cabin_fares' => fn () => $table->json('cabin_fares')->nullable(),
                'stops' => fn () => $table->unsignedTinyInteger('stops')->default(0),
                'stops_type' => fn () => $table->string('stops_type', 100)->nullable(),
                'stops_types' => fn () => $table->json('stops_types')->nullable(),
            ];

            foreach ($columns as $column => $definition) {
                if (! Schema::hasColumn('flight_routes', $column)) {
                    $definition();
                }
            }
        });

        if (! Schema::hasIndex('flight_routes', 'idx_flight_routes_tenant_flight_date')) {
            Schema::table('flight_routes', function (Blueprint $table): void {
                $table->index(['tenant_id', 'flight_number', 'flight_date'], 'idx_flight_routes_tenant_flight_date');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('flight_routes')) {
            return;
        }

        if (Schema::hasIndex('flight_routes', 'idx_flight_routes_tenant_flight_date')) {
            Schema::table('flight_routes', fn (Blueprint $table) => $table->dropIndex('idx_flight_routes_tenant_flight_date'));
        }

        $columns = [
            'flight_number', 'flight_date', 'flight_time', 'end_date', 'end_time', 'purchase_date',
            'pnr_number', 'departure_terminal', 'arrival_terminal', 'adult_count', 'child_count',
            'total_seats', 'available_seats', 'consumed_seats', 'total_adult_seats', 'total_child_seats',
            'available_adult_seats', 'available_child_seats', 'consumed_adult_seats', 'consumed_child_seats',
            'total_seats_price', 'adult_price', 'child_price', 'infant_price', 'gst_percentage', 'gst_amount',
            'total_adult_price_with_gst', 'total_child_price_with_gst', 'total_infant_price_with_gst', 'fare_families',
            'fare_family_prices', 'fare_family_pax_prices', 'cabin_fares', 'stops', 'stops_type', 'stops_types',
        ];

        foreach ($columns as $column) {
            if (Schema::hasColumn('flight_routes', $column)) {
                Schema::table('flight_routes', fn (Blueprint $table) => $table->dropColumn($column));
            }
        }

        // Do not recreate the old airport-pair unique index: multiple schedules
        // may now share a route, so restoring it could fail or block valid data.
    }
};
