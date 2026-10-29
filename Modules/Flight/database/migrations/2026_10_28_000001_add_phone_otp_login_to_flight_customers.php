<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mobile OTP login creates accounts from a verified phone alone, so email
     * becomes optional. phone_verified_at gates phone-based booking lookup:
     * only a phone proven by OTP may see bookings made with that number.
     */
    public function up(): void
    {
        Schema::table('flight_customers', function (Blueprint $table) {
            $table->string('email', 160)->nullable()->change();
            if (! Schema::hasColumn('flight_customers', 'phone_verified_at')) {
                $table->timestamp('phone_verified_at')->nullable()->after('phone');
            }
        });
    }

    public function down(): void
    {
        Schema::table('flight_customers', function (Blueprint $table) {
            if (Schema::hasColumn('flight_customers', 'phone_verified_at')) {
                $table->dropColumn('phone_verified_at');
            }
        });
    }
};
