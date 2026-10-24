<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('flight_customers')) {
            return;
        }

        Schema::create('flight_customers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('email', 160)->unique();
            $table->string('phone', 24)->unique();
            $table->string('password');
            $table->string('frequent_flyer_number', 32)->unique();
            $table->string('membership_tier', 20)->default('Standard');
            $table->unsignedInteger('miles_balance')->default(0);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flight_customers');
    }
};
