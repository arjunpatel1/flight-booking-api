<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const MODULE_FEATURES = ['flight_coupons', 'flight_b2b_network', 'flight_commission', 'flight_wallet'];

    /**
     * Coupons, B2B, commissions and wallets are now separate plan features.
     * Until now every plan with flight_booking reached them, so give existing
     * flight plans all four to keep tenants' access unchanged; the platform
     * admin can untick them per plan afterwards. Also fixes the seeded
     * access_scope "global", which the plan form rejects.
     */
    public function up(): void
    {
        DB::table('subscription_plans')->where('access_scope', 'global')->update(['access_scope' => 'tenant']);

        DB::table('subscription_plans')->orderBy('id')->get(['id', 'features'])->each(function ($plan) {
            $features = json_decode((string) $plan->features, true) ?: [];
            if (! in_array('flight_booking', $features, true)) return;

            $merged = array_values(array_unique([...$features, ...self::MODULE_FEATURES]));
            if ($merged !== $features) {
                DB::table('subscription_plans')->where('id', $plan->id)->update(['features' => json_encode($merged)]);
            }
        });
    }

    public function down(): void
    {
        // Data fix only; removing the features again would revoke tenant access.
    }
};
