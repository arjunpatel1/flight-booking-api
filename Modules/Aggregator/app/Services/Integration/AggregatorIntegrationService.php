<?php

namespace Modules\Aggregator\Services\Integration;

use App\NexDine;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Modules\Aggregator\Enums\AggregatorProvider;
use Modules\Aggregator\Enums\AggregatorSyncStatus;
use Modules\Aggregator\Jobs\SyncAggregatorIntegrationJob;
use Modules\Aggregator\Models\AggregatorIntegration;
use Modules\Aggregator\Models\AggregatorSyncLog;
use Modules\Aggregator\Services\Providers\AggregatorProviderFactory;
use Modules\Support\GlobalStructureFilters;

class AggregatorIntegrationService implements AggregatorIntegrationServiceInterface
{
    public function __construct(private readonly AggregatorProviderFactory $providerFactory)
    {
    }

    public function label(): string
    {
        return __('aggregator::aggregator.integration');
    }

    public function getModel(): AggregatorIntegration
    {
        return new AggregatorIntegration();
    }

    public function get(array $filters = [], ?array $sorts = []): LengthAwarePaginator
    {
        return $this->getModel()
            ->query()
            ->filters($filters)
            ->sortBy($sorts)
            ->paginate(NexDine::paginate())
            ->withQueryString();
    }

    public function show(int $id): AggregatorIntegration
    {
        return $this->findOrFail($id);
    }

    public function findOrFail(int $id): Builder|array|EloquentCollection|AggregatorIntegration
    {
        return $this->getModel()
            ->query()
            ->with([
                'outletMappings.branch:id,name',
                'menuMappings.menu:id,name',
            ])
            ->findOrFail($id);
    }

    public function store(array $data): AggregatorIntegration
    {
        return $this->getModel()->query()->create($data);
    }

    public function update(int $id, array $data): AggregatorIntegration
    {
        $integration = $this->findOrFail($id);
        $integration->update($data);

        return $integration;
    }

    public function toggle(int $id, bool $active): AggregatorIntegration
    {
        $integration = $this->findOrFail($id);
        $integration->update(['is_active' => $active]);

        return $integration->refresh();
    }

    public function destroy(int|array|string $ids): bool
    {
        return $this->getModel()
            ->query()
            ->whereIn('id', parseIds($ids))
            ->delete() ?: false;
    }

    public function getFormMeta(): array
    {
        return [
            'providers' => AggregatorProvider::toArrayTrans(),
            'provider_capabilities' => $this->providerCapabilities(),
            'provider_documentation' => $this->providerDocumentations(),
        ];
    }

    public function getStructureFilters(): array
    {
        return [
            [
                'key' => 'provider',
                'label' => __('aggregator::attributes.integrations.provider'),
                'type' => 'select',
                'options' => AggregatorProvider::toArrayTrans(),
            ],
            [
                'key' => 'is_active',
                'label' => __('aggregator::attributes.integrations.is_active'),
                'type' => 'boolean',
            ],
            GlobalStructureFilters::from(),
            GlobalStructureFilters::to(),
        ];
    }

    public function dispatchSync(int $id, string $type): void
    {
        $this->show($id);
        SyncAggregatorIntegrationJob::dispatch($id, $type, ['manual' => true]);
    }

    public function providerStatus(int $id): array
    {
        $integration = $this->findOrFail($id);
        $capabilities = $this->resolveCapabilities($integration);
        $requiredFields = $this->requiredCredentialFields($integration);
        $missingFields = $this->missingCredentialFields($integration, $requiredFields);
        $lastSync = $integration->syncLogs()->latest()->first();
        $contractStatus = $this->contractStatus($integration);

        return [
            'integration' => [
                'id' => $integration->id,
                'name' => $integration->name,
                'provider' => $integration->provider?->toTrans(),
                'provider_id' => $integration->provider?->value,
                'is_active' => $integration->is_active,
                'base_url' => $integration->base_url,
            ],
            'status' => [
                'configured' => empty($missingFields),
                'active' => $integration->is_active,
                'contract_status' => $contractStatus,
                'official_contract_verified' => $this->officialContractVerified($integration),
                'health' => $this->healthStatus($integration, $missingFields),
                'missing_fields' => $missingFields,
                'required_fields' => $requiredFields,
                'webhook_configured' => filled($integration->webhook_secret),
                'last_checked_at' => dateTimeFormat(now()),
            ],
            'settings' => [
                'auto_sync' => $this->settingEnabled($integration, 'auto_sync', 'auto_sync_enabled'),
                'auto_menu_sync' => $this->settingEnabled($integration, 'auto_menu_sync'),
                'auto_status_sync' => $this->settingEnabled($integration, 'auto_status_sync'),
                'webhook_processing' => $this->settingEnabled($integration, 'webhook_processing', 'webhook_processing_enabled'),
                'retry_enabled' => $this->settingEnabled($integration, 'retry_enabled'),
            ],
            'capabilities' => $capabilities,
            'documentation' => $this->providerDocumentation($integration->provider),
            'apis' => $this->providerApiCatalog($integration, $capabilities),
            'sync_summary' => [
                'total' => $integration->syncLogs()->count(),
                'success' => $integration->syncLogs()->where('status', AggregatorSyncStatus::Success)->count(),
                'failed' => $integration->syncLogs()->where('status', AggregatorSyncStatus::Failed)->count(),
                'pending' => $integration->syncLogs()->whereIn('status', [
                    AggregatorSyncStatus::Pending,
                    AggregatorSyncStatus::Processing,
                    AggregatorSyncStatus::Retrying,
                ])->count(),
                'last_sync' => $lastSync ? [
                    'id' => $lastSync->id,
                    'type' => $lastSync->type?->toTrans(),
                    'status' => $lastSync->status?->toTrans(),
                    'message' => $lastSync->message,
                    'created_at' => dateTimeFormat($lastSync->created_at),
                    'finished_at' => $lastSync->finished_at ? dateTimeFormat($lastSync->finished_at) : null,
                ] : null,
            ],
        ];
    }

    public function testConnection(int $id): array
    {
        $status = $this->providerStatus($id);
        $integration = $this->findOrFail($id);
        $healthCheck = $this->healthCheck($integration);
        $contractStatus = $status['status']['contract_status'];
        $ready = $status['status']['configured'] && $status['status']['active'] && $status['status']['official_contract_verified'];

        return [
            ...$status,
            'check_result' => [
                'success' => $healthCheck['success'] ?? ($ready && $contractStatus !== 'pending_official_contract'),
                'message' => $healthCheck['message'] ?? ($contractStatus === 'pending_official_contract'
                    ? __('aggregator::aggregator.provider_contract_pending')
                    : ($ready
                        ? __('aggregator::aggregator.provider_ready')
                        : __('aggregator::aggregator.provider_not_configured'))),
                'health_check' => $healthCheck,
            ],
        ];
    }

    public function retryLog(int $logId): void
    {
        $log = AggregatorSyncLog::query()->findOrFail($logId);
        abort_if(!$log->aggregator_integration_id, 422, 'Sync log has no integration to retry.');
        abort_if($log->attempts >= $log->max_attempts, 422, 'Sync retry limit has been reached.');

        $integration = AggregatorIntegration::query()->findOrFail($log->aggregator_integration_id);
        abort_if(($integration->settings['retry_enabled'] ?? true) === false, 422, 'Retry is disabled for this integration.');

        $log->update([
            'status' => AggregatorSyncStatus::Retrying,
            'attempts' => $log->attempts + 1,
            'next_retry_at' => null,
        ]);

        SyncAggregatorIntegrationJob::dispatch(
            $log->aggregator_integration_id,
            $log->type->value,
            [
                ...($log->request_payload ?? []),
                'reference' => $log->reference,
                'max_attempts' => $log->max_attempts,
            ]
        );
    }

    private function providerCapabilities(): array
    {
        return collect(AggregatorProvider::cases())
            ->mapWithKeys(function (AggregatorProvider $provider) {
                $integration = new AggregatorIntegration([
                    'provider' => $provider,
                ]);

                return [
                    $provider->value => $this->providerFactory->make($integration)->capabilities(),
                ];
            })
            ->all();
    }

    private function providerDocumentations(): array
    {
        return collect(AggregatorProvider::cases())
            ->mapWithKeys(fn(AggregatorProvider $provider) => [
                $provider->value => $this->providerDocumentation($provider),
            ])
            ->all();
    }

    private function resolveCapabilities(AggregatorIntegration $integration): array
    {
        return array_replace(
            $this->providerFactory->make($integration)->capabilities(),
            $integration->settings['capabilities'] ?? []
        );
    }

    private function requiredCredentialFields(AggregatorIntegration $integration): array
    {
        return array_values($integration->settings['required_credential_fields'] ?? [
            'official_contract_verified',
            'api_url',
        ]);
    }

    private function missingCredentialFields(AggregatorIntegration $integration, array $fields): array
    {
        return collect($fields)
            ->filter(function (string $field) use ($integration) {
                if ($field === 'official_contract_verified') {
                    return !$this->officialContractVerified($integration);
                }

                if ($field === 'api_url') {
                    return blank($integration->base_url) && blank(Arr::get($integration->credentials ?: [], $field));
                }

                if ($field === 'webhook_secret') {
                    return blank($integration->webhook_secret) && blank(Arr::get($integration->credentials ?: [], $field));
                }

                return blank(Arr::get($integration->credentials ?: [], $field));
            })
            ->values()
            ->all();
    }

    private function healthStatus(AggregatorIntegration $integration, array $missingFields): string
    {
        if (!$integration->is_active) {
            return 'inactive';
        }

        if ($this->contractStatus($integration) !== 'official_contract_verified') {
            return 'pending_contract';
        }

        if (!empty($missingFields)) {
            return 'needs_configuration';
        }

        return 'ready';
    }

    private function contractStatus(AggregatorIntegration $integration): string
    {
        if ($this->officialContractVerified($integration)) {
            return 'official_contract_verified';
        }

        return $integration->settings['contract_status'] ?? 'pending_official_contract';
    }

    private function officialContractVerified(AggregatorIntegration $integration): bool
    {
        return filter_var($integration->settings['official_contract_verified'] ?? false, FILTER_VALIDATE_BOOL)
            || ($integration->settings['contract_status'] ?? null) === 'official_contract_verified';
    }

    private function providerDocumentation(?AggregatorProvider $provider): array
    {
        return match ($provider) {
            AggregatorProvider::Zomato => [
                'status' => 'public_reference_available',
                'requires_partner_credentials' => true,
                'official_urls' => [
                    'https://www.zomato.com/developer/integration/order-management/api-documentation/',
                    'https://www.zomato.com/developer/integration/order-management/order-relay-webhook/',
                ],
            ],
            AggregatorProvider::Ondc => [
                'status' => 'public_protocol_available',
                'requires_partner_credentials' => true,
                'official_urls' => [
                    'https://github.com/ONDC-Official/ONDC-RET-Specifications',
                    'https://github.com/ONDC-Official/beckn-protocol-specifications',
                ],
            ],
            AggregatorProvider::Swiggy => [
                'status' => 'partner_contract_required',
                'requires_partner_credentials' => true,
                'official_urls' => [],
            ],
            AggregatorProvider::Magicpin,
            AggregatorProvider::Dunzo,
            AggregatorProvider::Porter,
            AggregatorProvider::Blinkit,
            AggregatorProvider::UberEats => [
                'status' => 'partner_contract_required',
                'requires_partner_credentials' => true,
                'official_urls' => [],
            ],
            default => [
                'status' => 'partner_contract_required',
                'requires_partner_credentials' => true,
                'official_urls' => [],
            ],
        };
    }

    private function settingEnabled(AggregatorIntegration $integration, string $key, ?string $legacyKey = null): bool
    {
        if (array_key_exists($key, $integration->settings ?: [])) {
            return (bool) $integration->settings[$key];
        }

        if ($legacyKey && array_key_exists($legacyKey, $integration->settings ?: [])) {
            return (bool) $integration->settings[$legacyKey];
        }

        return true;
    }

    private function providerApiCatalog(AggregatorIntegration $integration, array $capabilities): array
    {
        $id = $integration->id;
        $officialContractVerified = $this->officialContractVerified($integration);

        return [
            [
                'key' => 'health_check',
                'name' => __('aggregator::aggregator.apis.health_check'),
                'method' => $this->endpointMethod($integration, 'health_check', 'GET'),
                'endpoint' => null,
                'external_endpoint' => $this->externalEndpoint($integration, 'health_check'),
                'queue' => null,
                'supported' => true,
                'enabled' => $officialContractVerified && $this->endpointEnabled($integration, 'health_check'),
                'contract_required' => true,
            ],
            [
                'key' => 'webhook_receive',
                'name' => __('aggregator::aggregator.apis.webhook_receive'),
                'method' => 'POST',
                'endpoint' => "/api/v1/aggregator-webhook-events/{$id}/receive",
                'queue' => 'aggregator-webhooks',
                'supported' => true,
                'enabled' => $officialContractVerified && $this->settingEnabled($integration, 'webhook_processing', 'webhook_processing_enabled'),
                'contract_required' => true,
            ],
            [
                'key' => 'order_sync',
                'name' => __('aggregator::aggregator.apis.order_sync'),
                'method' => 'EVENT',
                'endpoint' => 'OrderCreated -> AggregatorOrderSyncJob',
                'external_endpoint' => $this->externalEndpoint($integration, 'order_sync'),
                'queue' => 'aggregator-sync',
                'supported' => true,
                'enabled' => $officialContractVerified && $this->settingEnabled($integration, 'auto_sync', 'auto_sync_enabled') && $this->endpointEnabled($integration, 'order_sync'),
                'contract_required' => true,
            ],
            [
                'key' => 'status_sync',
                'name' => __('aggregator::aggregator.apis.status_sync'),
                'method' => 'EVENT',
                'endpoint' => 'OrderUpdateStatus -> AggregatorStatusSyncJob',
                'external_endpoint' => $this->externalEndpoint($integration, 'status_sync'),
                'queue' => 'aggregator-status',
                'supported' => (bool) ($capabilities['supports_status_push'] ?? false),
                'enabled' => $officialContractVerified && $this->settingEnabled($integration, 'auto_status_sync') && $this->endpointEnabled($integration, 'status_sync'),
                'contract_required' => true,
            ],
            [
                'key' => 'menu_sync',
                'name' => __('aggregator::aggregator.apis.menu_sync'),
                'method' => 'POST',
                'endpoint' => "/api/v1/aggregator-integrations/{$id}/sync",
                'external_endpoint' => $this->externalEndpoint($integration, 'menu_sync'),
                'queue' => 'aggregator-menu',
                'supported' => (bool) ($capabilities['supports_menu_sync'] ?? false),
                'enabled' => $officialContractVerified && $this->settingEnabled($integration, 'auto_menu_sync') && $this->endpointEnabled($integration, 'menu_sync'),
                'contract_required' => true,
            ],
            [
                'key' => 'outlet_mapping',
                'name' => __('aggregator::aggregator.apis.outlet_mapping'),
                'method' => 'CRUD',
                'endpoint' => '/api/v1/aggregator-outlet-mappings',
                'queue' => null,
                'supported' => true,
                'enabled' => true,
            ],
            [
                'key' => 'menu_mapping',
                'name' => __('aggregator::aggregator.apis.menu_mapping'),
                'method' => 'CRUD',
                'endpoint' => '/api/v1/aggregator-menu-mappings',
                'queue' => null,
                'supported' => true,
                'enabled' => true,
            ],
            [
                'key' => 'sync_logs',
                'name' => __('aggregator::aggregator.apis.sync_logs'),
                'method' => 'GET',
                'endpoint' => '/api/v1/aggregator-sync-logs',
                'queue' => null,
                'supported' => true,
                'enabled' => true,
            ],
            [
                'key' => 'refunds',
                'name' => __('aggregator::aggregator.apis.refunds'),
                'method' => 'CONTRACT',
                'endpoint' => null,
                'queue' => 'aggregator-sync',
                'supported' => (bool) ($capabilities['supports_refunds'] ?? false),
                'enabled' => false,
                'contract_required' => true,
            ],
            [
                'key' => 'delivery_tracking',
                'name' => __('aggregator::aggregator.apis.delivery_tracking'),
                'method' => 'CONTRACT',
                'endpoint' => null,
                'queue' => 'tracking',
                'supported' => (bool) ($capabilities['supports_delivery_tracking'] ?? false),
                'enabled' => false,
                'contract_required' => true,
            ],
            ...$this->providerSpecificApiCatalog($integration, $officialContractVerified),
        ];
    }

    private function providerSpecificApiCatalog(AggregatorIntegration $integration, bool $officialContractVerified): array
    {
        if ($integration->provider !== AggregatorProvider::Zomato) {
            return [];
        }

        return collect($integration->settings['status_endpoints'] ?? [])
            ->map(function (array $endpoint, string $key) use ($officialContractVerified) {
                return [
                    'key' => "zomato_status_{$key}",
                    'name' => __('aggregator::aggregator.apis.zomato_status', ['status' => str($key)->replace('_', ' ')->title()]),
                    'method' => strtoupper($endpoint['method'] ?? 'POST'),
                    'endpoint' => null,
                    'external_endpoint' => $endpoint['url'] ?? null,
                    'queue' => 'aggregator-status',
                    'supported' => true,
                    'enabled' => $officialContractVerified && (bool) ($endpoint['enabled'] ?? false),
                    'contract_required' => true,
                ];
            })
            ->values()
            ->all();
    }

    private function externalEndpoint(AggregatorIntegration $integration, string $type): ?string
    {
        $endpoint = $integration->settings['api_endpoints'][$type] ?? null;

        if (is_string($endpoint)) {
            return $endpoint;
        }

        return is_array($endpoint) ? ($endpoint['url'] ?? null) : null;
    }

    private function endpointEnabled(AggregatorIntegration $integration, string $type): bool
    {
        $endpoint = $integration->settings['api_endpoints'][$type] ?? null;

        return is_array($endpoint) ? (bool) ($endpoint['enabled'] ?? false) : filled($endpoint);
    }

    private function endpointMethod(AggregatorIntegration $integration, string $type, string $default = 'POST'): string
    {
        $endpoint = $integration->settings['api_endpoints'][$type] ?? null;

        return strtoupper(is_array($endpoint) ? ($endpoint['method'] ?? $default) : $default);
    }

    private function healthCheck(AggregatorIntegration $integration): ?array
    {
        if (!$this->officialContractVerified($integration)) {
            return [
                'success' => false,
                'message' => __('aggregator::aggregator.provider_contract_pending'),
                'contract_status' => 'pending_official_contract',
            ];
        }

        $endpoint = $integration->settings['api_endpoints']['health_check'] ?? null;
        $url = is_array($endpoint) ? ($endpoint['url'] ?? null) : $endpoint;

        if (!$this->endpointEnabled($integration, 'health_check') || blank($url)) {
            return null;
        }

        $method = strtolower(is_array($endpoint) ? ($endpoint['method'] ?? 'GET') : 'GET');
        $headers = is_array($endpoint) ? ($endpoint['headers'] ?? []) : [];
        $timeout = (int) (is_array($endpoint) ? ($endpoint['timeout'] ?? 15) : 15);
        $response = Http::timeout($timeout)
            ->acceptJson()
            ->withHeaders($this->renderEndpointValues($headers, $integration))
            ->{$method}($this->resolveEndpointUrl($url, $integration));

        return [
            'success' => $response->successful(),
            'message' => $response->successful()
                ? __('aggregator::aggregator.provider_request_success')
                : __('aggregator::aggregator.provider_request_failed'),
            'status' => $response->status(),
            'response' => $response->json() ?? $response->body(),
        ];
    }

    private function renderEndpointValues(array $values, AggregatorIntegration $integration): array
    {
        return collect($values)
            ->map(fn($value) => is_string($value)
                ? str_replace(
                    ['{{api_key}}', '{{client_id}}', '{{client_secret}}', '{{base_url}}'],
                    [
                        Arr::get($integration->credentials ?: [], 'api_key', ''),
                        Arr::get($integration->credentials ?: [], 'client_id', ''),
                        Arr::get($integration->credentials ?: [], 'client_secret', ''),
                        $integration->base_url ?: '',
                    ],
                    $value
                )
                : $value)
            ->all();
    }

    private function resolveEndpointUrl(string $url, AggregatorIntegration $integration): string
    {
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://') || blank($integration->base_url)) {
            return $url;
        }

        return rtrim($integration->base_url, '/') . '/' . ltrim($url, '/');
    }
}
