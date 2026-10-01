<?php

namespace Modules\Aggregator\Services\Providers;

use Modules\Aggregator\Models\AggregatorIntegration;

class ZomatoProvider extends ConfiguredAggregatorProvider
{
    public function __construct()
    {
        parent::__construct('zomato');
    }

    public function capabilities(): array
    {
        return [
            'supports_menu_sync' => false,
            'supports_status_push' => true,
            'supports_inventory_sync' => false,
            'supports_refunds' => false,
            'supports_delivery_tracking' => true,
        ];
    }

    public function statusSync(AggregatorIntegration $integration, array $payload = []): array
    {
        $endpointKey = ZomatoContract::statusEndpointKey($payload['status'] ?? null);

        if (!$endpointKey || blank($payload['external_order_id'] ?? null)) {
            return [
                'success' => false,
                'message' => __('aggregator::aggregator.provider_status_mapping_missing'),
                'provider' => 'zomato',
                'type' => 'status_sync',
                'status' => $payload['status'] ?? null,
            ];
        }

        $endpoint = $integration->settings['status_endpoints'][$endpointKey]
            ?? ZomatoContract::statusEndpointDefaults()[$endpointKey]
            ?? [];

        return $this->sendEndpointRequest($integration, 'status_sync', $endpoint, $payload);
    }
}
