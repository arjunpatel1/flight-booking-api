<?php

namespace Modules\Flight\Services\Pricing;

use Modules\Flight\Models\FlightFare;
use Modules\Saas\Models\Tenant;

/**
 * The convenience fee a tenant charges per passenger, per flight (Booking &
 * Ticket Settings). It is stored on each fare, so search, quotes, holds and
 * bookings all price it the same way; quotes and bookings already made keep the
 * price they were given.
 */
class FlightConvenienceFee
{
    private const SETTING = 'flight_booking.convenience_fee_per_passenger';

    public function __construct(private readonly FlightPricingEngine $pricing)
    {
    }

    /** Null when the tenant has never set a fee: fares then keep their own value. */
    public function amountFor(?int $tenantId): ?float
    {
        if (! $tenantId) {
            return null;
        }

        $amount = data_get(Tenant::query()->withoutGlobalScopes()->find($tenantId)?->settings, self::SETTING);

        return $amount === null ? null : (float) $amount;
    }

    /** Puts the fee on all of the tenant's fares and reprices them. */
    public function applyToFares(int $tenantId, float $amount): void
    {
        FlightFare::query()->withoutGlobalScopes()->where('tenant_id', $tenantId)
            ->chunkById(200, function ($fares) use ($amount) {
                foreach ($fares as $fare) {
                    $pricing = $this->pricing->calculate([
                        'supplier_fare' => (float) $fare->supplier_fare,
                        'pricing_rules_adjustment' => (float) $fare->pricing_rules_adjustment,
                        'taxes' => (float) $fare->taxes,
                        'supplier_fees' => (float) $fare->supplier_fees,
                        'platform_markup' => (float) $fare->platform_markup,
                        'tenant_markup' => (float) $fare->tenant_markup,
                        'client_markup' => (float) $fare->client_markup,
                        'convenience_fee' => $amount,
                        'gst' => (float) $fare->gst,
                        'discount' => (float) $fare->discount,
                    ]);

                    $fare->update([
                        'convenience_fee' => $pricing['convenience_fee'],
                        'final_customer_price' => $pricing['final_customer_price'],
                    ]);
                }
            });
    }
}
