<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Modules\Flight\Services\Catalog\TimezoneCatalog;

return new class extends Migration
{
    /**
     * Airports saved with a retired zone name (e.g. Asia/Calcutta, which some
     * browsers still list) get its current name (Asia/Kolkata). New saves are
     * translated by the airport controller.
     */
    public function up(): void
    {
        $timezones = app(TimezoneCatalog::class);

        DB::table('flight_airports')->whereNotNull('timezone')->distinct()->pluck('timezone')
            ->each(function (string $zone) use ($timezones) {
                $current = $timezones->current($zone);
                if ($current !== $zone) {
                    DB::table('flight_airports')->where('timezone', $zone)->update(['timezone' => $current]);
                }
            });
    }

    public function down(): void
    {
        // Data fix only; the old and new names are the same zone.
    }
};
