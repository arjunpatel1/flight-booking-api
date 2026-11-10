<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

/**
 * New agencies start with no cabin classes; a test that books or prices
 * flights adds the classes it uses, as the agency admin would.
 */
trait AgencyCabinClasses
{
    protected function addCabinClasses(int $tenantId, array $codes = ['ECONOMY', 'PREMIUM_ECONOMY', 'BUSINESS', 'FIRST']): void
    {
        foreach (array_values($codes) as $i => $code) {
            DB::table('flight_cabin_classes')->updateOrInsert(
                ['tenant_id' => $tenantId, 'code' => $code],
                ['name' => ucwords(strtolower(str_replace('_', ' ', $code))), 'sort_order' => $i + 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            );
        }
    }
}
