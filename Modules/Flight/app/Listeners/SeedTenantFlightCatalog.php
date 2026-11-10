<?php

namespace Modules\Flight\Listeners;

use Modules\Flight\Services\Catalog\FlightReferenceCatalog;
use Modules\Saas\Models\Tenant;

/**
 * A new tenant starts with an empty flight catalog: no airports, airlines,
 * aircraft or cabin classes. The agency adds the cabin classes it sells
 * (Fare Families → Cabin Classes) before creating fare families or inventory.
 *
 * Only when flight.copy_starter_catalog is on is the whole starter set copied.
 */
class SeedTenantFlightCatalog
{
    public function __construct(private readonly FlightReferenceCatalog $catalog)
    {
    }

    public function handle(Tenant $tenant): void
    {
        if (! config('flight.copy_starter_catalog', false)) {
            return;
        }

        $this->catalog->copyStarterSet((int) $tenant->id);
    }
}
