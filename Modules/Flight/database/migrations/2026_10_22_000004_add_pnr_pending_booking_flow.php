<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('flight_bookings')) {
            return;
        }

        Schema::table('flight_bookings', function (Blueprint $table): void {
            if (! Schema::hasColumn('flight_bookings', 'manual_pnr_required')) {
                $table->boolean('manual_pnr_required')->default(false)->after('supplier_pnr');
            }

            $table->enum('booking_status', [
                'DRAFT', 'QUOTED', 'PAYMENT_PENDING', 'PNR_PENDING', 'BOOKED',
                'CONFIRMED', 'CANCELLED', 'FAILED', 'COMPLETED',
            ])->default('DRAFT')->change();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('flight_bookings')) {
            return;
        }

        // Preserve records in the new states before restoring the old enum.
        \Illuminate\Support\Facades\DB::table('flight_bookings')
            ->whereIn('booking_status', ['PNR_PENDING', 'BOOKED'])
            ->update(['booking_status' => 'CONFIRMED']);

        Schema::table('flight_bookings', function (Blueprint $table): void {
            $table->enum('booking_status', [
                'DRAFT', 'QUOTED', 'PAYMENT_PENDING', 'CONFIRMED', 'CANCELLED', 'FAILED', 'COMPLETED',
            ])->default('DRAFT')->change();

            if (Schema::hasColumn('flight_bookings', 'manual_pnr_required')) {
                $table->dropColumn('manual_pnr_required');
            }
        });
    }
};
