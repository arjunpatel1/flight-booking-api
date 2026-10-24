<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flight_offer_redemptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('voucher_id')->index();
            $table->unsignedBigInteger('booking_id')->unique();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->timestamps();
            $table->unique(['voucher_id', 'booking_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flight_offer_redemptions');
    }
};
