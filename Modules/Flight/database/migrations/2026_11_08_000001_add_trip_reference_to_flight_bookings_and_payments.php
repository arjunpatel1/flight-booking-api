<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Round-trip and multi-city trips: each flight stays its own booking (own
 * PNR, e-ticket, cancellation and refund), linked by a trip reference and
 * paid in one gateway payment. Every flight's payment row shares the trip's
 * gateway order and records that flight's own share of the amount.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('flight_bookings', function (Blueprint $table) {
            if (! Schema::hasColumn('flight_bookings', 'trip_reference')) {
                $table->string('trip_reference', 40)->nullable()->index()->after('booking_reference');
            }
            if (! Schema::hasColumn('flight_bookings', 'trip_leg')) {
                $table->unsignedTinyInteger('trip_leg')->nullable()->after('trip_reference');
            }
        });

        Schema::table('flight_payments', function (Blueprint $table) {
            if (! Schema::hasColumn('flight_payments', 'trip_reference')) {
                $table->string('trip_reference', 40)->nullable()->index()->after('flight_booking_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('flight_payments', function (Blueprint $table) {
            if (Schema::hasColumn('flight_payments', 'trip_reference')) {
                $table->dropIndex(['trip_reference']);
                $table->dropColumn('trip_reference');
            }
        });

        Schema::table('flight_bookings', function (Blueprint $table) {
            if (Schema::hasColumn('flight_bookings', 'trip_leg')) {
                $table->dropColumn('trip_leg');
            }
            if (Schema::hasColumn('flight_bookings', 'trip_reference')) {
                $table->dropIndex(['trip_reference']);
                $table->dropColumn('trip_reference');
            }
        });
    }
};
