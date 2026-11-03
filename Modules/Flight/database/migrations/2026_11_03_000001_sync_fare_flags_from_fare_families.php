<?php

use Illuminate\Database\Migrations\Migration;
use Modules\Flight\Models\FlightFareFamily;

return new class extends Migration
{
    /**
     * Fares made before their family was edited kept the old refundable /
     * changeable flags, so cancellations of refundable families quoted as
     * non-refundable. Family edits now update their fares; this catches up
     * the fares that drifted before that.
     */
    public function up(): void
    {
        FlightFareFamily::withoutGlobalScopes()->get()
            ->each(fn (FlightFareFamily $family) => $family->syncFlagsToFares());
    }

    public function down(): void
    {
        // Data fix only; the previous flags were wrong.
    }
};
