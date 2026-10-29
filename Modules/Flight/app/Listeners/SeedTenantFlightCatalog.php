<?php

namespace Modules\Flight\Listeners;

use Modules\Flight\Services\Catalog\FlightReferenceCatalog;
use Modules\Saas\Models\Tenant;

/**
 * Gives a new tenant its own copy of the standard cabin classes (Economy,
 * Premium Economy, Business, First) so it can create inventory and fares
 * straight away. Airports, airlines and aircraft start empty unless
 * flight.copy_starter_catalog is on, in which case the whole starter set is
 * copied.
 */
class SeedTenantFlightCatalog
{
    public function __construct(private readonly FlightReferenceCatalog $catalog)
    {
    }

    public function handle(Tenant $tenant): void
    {
        $this->catalog->copyStarterSet(
            (int) $tenant->id,
            config('flight.copy_starter_catalog', false) ? null : ['flight_cabin_classes'],
        );
    }
}
