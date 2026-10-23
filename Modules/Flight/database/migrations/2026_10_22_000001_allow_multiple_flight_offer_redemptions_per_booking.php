<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('flight_offer_redemptions', function (Blueprint $table): void {
            $table->dropUnique('flight_offer_redemptions_booking_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('flight_offer_redemptions', function (Blueprint $table): void {
            $table->unique('booking_id');
        });
    }
};
