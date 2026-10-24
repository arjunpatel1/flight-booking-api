<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 16 — PNR, Tickets & E-Ticket Management
 *
 * Tables & Alterations:
 *   - Alters `flight_bookings` to support PNR, supplier booking references, ticket status and ticketing timestamps.
 *   - Creates `flight_tickets` table for passenger-ticket association, e-ticket numbers, seats, baggage, and fare allocations.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Add PNR and ticketing columns to flight_bookings if not already present
        if (Schema::hasTable('flight_bookings')) {
            Schema::table('flight_bookings', function (Blueprint $table) {
                if (! Schema::hasColumn('flight_bookings', 'pnr')) {
                    $table->string('pnr', 20)->nullable()->index()->after('booking_reference');
                }
                if (! Schema::hasColumn('flight_bookings', 'supplier_booking_reference')) {
                    $table->string('supplier_booking_reference', 100)->nullable()->after('supplier_code');
                }
                if (! Schema::hasColumn('flight_bookings', 'supplier_pnr')) {
                    $table->string('supplier_pnr', 50)->nullable()->after('supplier_booking_reference');
                }
                if (! Schema::hasColumn('flight_bookings', 'ticket_status')) {
                    $table->string('ticket_status', 30)->default('PENDING')->index()->after('booking_status');
                }
                if (! Schema::hasColumn('flight_bookings', 'ticketed_at')) {
                    $table->timestamp('ticketed_at')->nullable()->after('confirmed_at');
                }
                if (! Schema::hasColumn('flight_bookings', 'ticketing_error')) {
                    $table->text('ticketing_error')->nullable()->after('cancellation_reason');
                }
                if (! Schema::hasColumn('flight_bookings', 'eticket_pdf_path')) {
                    $table->string('eticket_pdf_path', 255)->nullable()->after('meta');
                }
            });
        }

        // 2. Create flight_tickets table
        if (! Schema::hasTable('flight_tickets')) {
            Schema::create('flight_tickets', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->unsignedBigInteger('flight_booking_id')->index();
                $table->unsignedBigInteger('flight_booking_passenger_id')->index();

                // Identifiers
                $table->string('ticket_number', 50)->index()->comment('Standard airline ticket number e.g. 098-XXXXXXXXXX');
                $table->string('e_ticket_number', 50)->nullable()->index();
                $table->string('pnr', 20)->index();
                $table->string('supplier_code', 50)->default('INTERNAL');

                // Controlled Status
                $table->string('status', 30)->default('ISSUED')->index(); // ISSUED, VOIDED, REFUNDED, EXCHANGED

                // Passenger Allocations
                $table->string('seat_number', 20)->nullable();
                $table->string('baggage_allowance', 100)->nullable()->comment('e.g. 15kg Check-in + 7kg Cabin');
                $table->string('meal_preference', 100)->nullable();

                // Fare Allocation per Ticket
                $table->decimal('fare_amount', 12, 2)->default(0.00);
                $table->decimal('tax_amount', 12, 2)->default(0.00);
                $table->decimal('total_amount', 12, 2)->default(0.00);
                $table->string('currency', 3)->default('INR');

                $table->string('validating_carrier', 10)->nullable()->comment('IATA 2-letter airline code');
                $table->timestamp('issued_at')->nullable();
                $table->json('meta')->nullable();

                $table->timestamps();
                $table->softDeletes();

                $table->foreign('flight_booking_id')
                    ->references('id')->on('flight_bookings')->onDelete('cascade');
                $table->foreign('flight_booking_passenger_id')
                    ->references('id')->on('flight_booking_passengers')->onDelete('cascade');

                $table->unique(['flight_booking_id', 'flight_booking_passenger_id'], 'uniq_bkng_pax_ticket');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('flight_tickets');

        if (Schema::hasTable('flight_bookings')) {
            Schema::table('flight_bookings', function (Blueprint $table) {
                $columns = ['pnr', 'supplier_booking_reference', 'supplier_pnr', 'ticket_status', 'ticketed_at', 'ticketing_error', 'eticket_pdf_path'];
                foreach ($columns as $column) {
                    if (Schema::hasColumn('flight_bookings', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
