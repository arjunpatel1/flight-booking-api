<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Guest booking endpoints used to answer anyone who knew the booking
     * reference (or its sequential id), including passport numbers. A guest
     * now needs the token issued when the booking was created.
     */
    public function up(): void
    {
        Schema::table('flight_bookings', function (Blueprint $table) {
            if (! Schema::hasColumn('flight_bookings', 'access_token_hash')) {
                $table->string('access_token_hash', 64)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('flight_bookings', function (Blueprint $table) {
            if (Schema::hasColumn('flight_bookings', 'access_token_hash')) {
                $table->dropColumn('access_token_hash');
            }
        });
    }
};
