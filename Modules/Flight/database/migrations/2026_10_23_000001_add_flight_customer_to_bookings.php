<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('flight_bookings', 'flight_customer_id')) {
            Schema::table('flight_bookings', function (Blueprint $table): void {
                $table->unsignedBigInteger('flight_customer_id')->nullable()->after('user_id')->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('flight_bookings', 'flight_customer_id')) {
            Schema::table('flight_bookings', function (Blueprint $table): void {
                $table->dropIndex(['flight_customer_id']);
                $table->dropColumn('flight_customer_id');
            });
        }
    }
};
