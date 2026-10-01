<?php

namespace Modules\Aggregator\Services\Validation;

use Modules\Aggregator\Models\AggregatorIntegration;

class AggregatorMappingValidator
{
    public function validateOutlet(AggregatorIntegration $integration, ?int $branchId): ?string
    {
        if (!$branchId) {
            return 'Order has no branch assigned.';
        }

        $exists = $integration->outletMappings()
            ->where('branch_id', $branchId)
            ->where('is_active', true)
            ->exists();

        return $exists ? null : 'No active outlet mapping exists for this order branch.';
    }

    public function validateMenu(AggregatorIntegration $integration): ?string
    {
        return $integration->menuMappings()->where('sync_enabled', true)->exists()
            ? null
            : 'No enabled menu mapping exists for this integration.';
    }
}
