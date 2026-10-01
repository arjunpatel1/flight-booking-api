<?php

namespace Modules\Aggregator\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Aggregator\Enums\AggregatorProvider;
use Modules\Aggregator\Models\AggregatorIntegration;
use Modules\Aggregator\Services\Providers\ZomatoContract;
use Modules\Setting\Models\Setting;

class AggregatorDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->marketplaceProviders() as $provider => $config) {
            $integrations = AggregatorIntegration::query()
                ->where('provider', $provider)
                ->oldest('id')
                ->get();
            $integration = $integrations
                ->first(fn(AggregatorIntegration $item) => !($item->settings['is_seeded'] ?? false))
                ?: $integrations->first();

            if ($integration) {
                $wasSeeded = (bool) ($integration->settings['is_seeded'] ?? false);
                $settings = array_replace_recursive(
                    $config['settings'],
                    $this->cleanLegacySettings($integration->settings ?: []),
                );
                $settings['is_seeded'] = $wasSeeded;

                $integration->update(['settings' => $settings]);

                $integrations
                    ->reject(fn(AggregatorIntegration $item) => $item->id === $integration->id)
                    ->filter(fn(AggregatorIntegration $item) => (bool) ($item->settings['is_seeded'] ?? false))
                    ->each(fn(AggregatorIntegration $item) => $item->delete());

                continue;
            }

            AggregatorIntegration::query()->create([
                'provider' => $provider,
                'name' => $config['name'],
                'base_url' => null,
                'credentials' => [],
                'webhook_secret' => null,
                'settings' => $config['settings'],
                'is_active' => false,
            ]);
        }

        Setting::set('third_party_integrations', $this->thirdPartyCatalog());
        Setting::set('aggregator_provider_colors', [
            'swiggy' => '#fc8019',
            'zomato' => '#e23744',
            'ondc' => '#2563eb',
            'magicpin' => '#7c3aed',
            'dunzo' => '#16a34a',
            'porter' => '#0f766e',
            'blinkit' => '#f7cb00',
            'uber_eats' => '#06c167',
        ]);
    }

    private function cleanLegacySettings(array $settings): array
    {
        unset($settings['auto_sync_enabled'], $settings['webhook_processing_enabled']);

        return $settings;
    }

    private function marketplaceProviders(): array
    {
        return [
            AggregatorProvider::Swiggy->value => $this->marketplace('Swiggy', false, false, [], ['api_url', 'client_id', 'client_secret']),
            AggregatorProvider::Zomato->value => $this->marketplace(
                'Zomato',
                false,
                true,
                ['delivery_tracking', 'public_official_reference'],
                ['api_url', 'api_key', 'webhook_secret'],
                [
                    'status_endpoints' => ZomatoContract::statusEndpointDefaults(),
                ],
            ),
            AggregatorProvider::Ondc->value => $this->marketplace('ONDC', false, false, ['catalog_sync', 'order_sync', 'logistics'], ['api_url', 'client_id', 'client_secret', 'merchant_id']),
            AggregatorProvider::Magicpin->value => $this->marketplace('Magicpin', false, false, [], ['api_url', 'api_key', 'merchant_id']),
            AggregatorProvider::Dunzo->value => $this->marketplace('Dunzo', false, false, ['delivery_tracking', 'logistics'], ['api_url', 'api_key']),
            AggregatorProvider::Porter->value => $this->marketplace('Porter', false, false, ['delivery_tracking', 'logistics'], ['api_url', 'api_key']),
            AggregatorProvider::Blinkit->value => $this->marketplace('Blinkit', false, false, ['quick_commerce', 'catalog_sync', 'order_sync'], ['api_url', 'api_key', 'merchant_id', 'store_id']),
            AggregatorProvider::UberEats->value => $this->marketplace('Uber Eats', false, false, ['marketplace', 'delivery_tracking'], ['api_url', 'client_id', 'client_secret', 'store_id']),
        ];
    }

    private function marketplace(string $name, bool $menuSync = false, bool $statusPush = false, array $tags = [], array $requiredFields = ['api_url'], array $extraSettings = []): array
    {
        return [
            'name' => $name,
            'settings' => array_replace_recursive([
                'is_seeded' => true,
                'contract_status' => 'pending_official_contract',
                'auto_sync' => false,
                'auto_menu_sync' => false,
                'auto_status_sync' => false,
                'sync_direct_orders' => false,
                'webhook_processing' => false,
                'retry_enabled' => true,
                'tags' => $tags,
                'required_credential_fields' => $requiredFields,
                'api_headers' => [],
                'api_endpoints' => [
                    'health_check' => $this->endpointConfig('GET'),
                    'order_sync' => $this->endpointConfig(),
                    'menu_sync' => $this->endpointConfig(),
                    'status_sync' => $this->endpointConfig(),
                ],
                'credential_fields' => [
                    'api_url',
                    'client_id',
                    'api_key',
                    'client_secret',
                    'webhook_secret',
                    'merchant_id',
                    'store_id',
                ],
                'capabilities' => [
                    'supports_menu_sync' => $menuSync,
                    'supports_status_push' => $statusPush,
                    'supports_inventory_sync' => false,
                    'supports_refunds' => false,
                    'supports_delivery_tracking' => in_array('delivery_tracking', $tags, true),
                ],
            ], $extraSettings),
        ];
    }

    private function endpointConfig(string $method = 'POST'): array
    {
        return [
            'enabled' => false,
            'method' => $method,
            'url' => null,
            'headers' => [],
            'body' => [],
            'timeout' => 30,
        ];
    }

    private function thirdPartyCatalog(): array
    {
        return [
            'marketplaces' => [
                'swiggy',
                'zomato',
                'ondc',
                'magicpin',
                'blinkit',
                'uber_eats',
            ],
            'logistics' => [
                'dunzo',
                'porter',
                'ondc_logistics',
            ],
            'whatsapp' => [
                'msg91',
                'meta',
                'twilio',
                'gupshup',
                'interakt',
            ],
            'payments' => [
                'razorpay',
                'stripe',
                'cashfree',
                'phonepe',
                'paytm',
            ],
            'maps' => [
                'google_maps',
                'mapbox',
            ],
            'accounting' => [
                'tally',
                'zoho_books',
                'quickbooks',
            ],
            'communication' => [
                'email',
                'sms',
                'push',
                'whatsapp',
            ],
        ];
    }
}
