<?php

namespace Modules\Flight\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\Controller;
use Modules\Flight\Enums\SupplierHealthStatus;
use Modules\Flight\Enums\SupplierMappingType;
use Modules\Flight\Enums\SupplierType;
use Modules\Flight\Models\FlightSupplier;
use Modules\Flight\Models\FlightCabinClass;
use Modules\Flight\Models\FlightSupplierConfiguration;
use Modules\Flight\Models\FlightSupplierCredential;
use Modules\Flight\Models\FlightSupplierMapping;
use Modules\Flight\Services\Supplier\SupplierHealthService;
use Modules\Flight\Services\Supplier\SupplierManager;
use Modules\Flight\Services\Supplier\SupplierMappingService;
use Modules\Support\ApiResponse;

/**
 * Phase 5: Supplier Administration API Controller
 *
 * Exposes endpoints for managing external flight supply integrations,
 * configuration, credentials, health status, and entity mappings.
 */
class FlightSupplierController extends Controller
{
    public function __construct(
        protected SupplierManager $supplierManager,
        protected SupplierHealthService $healthService,
        protected SupplierMappingService $mappingService,
    ) {}

    /**
     * List all suppliers.
     *
     * GET /flight/suppliers
     */
    public function index(Request $request): JsonResponse
    {
        $query = FlightSupplier::with(['configuration', 'credentials'])
            ->orderBy('priority', 'desc')
            ->orderBy('name');

        if ($type = $request->query('type')) {
            $query->where('type', $type);
        }

        if ($health = $request->query('health_status')) {
            $query->where('health_status', $health);
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $perPage = $request->integer('per_page', 20);

        return ApiResponse::pagination($query->paginate($perPage));
    }

    /**
     * Create a new supplier.
     *
     * POST /flight/suppliers
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code'                  => 'required|string|max:50',
            'name'                  => 'required|string|max:150',
            'type'                  => 'required|in:gds,ndc,airline_api,consolidator,manual,contracted',
            'adapter_class'         => 'required|string|max:255',
            'environment'           => 'nullable|in:sandbox,production,test',
            'is_active'             => 'nullable|boolean',
            'priority'              => 'nullable|integer',
            'timeout_seconds'       => 'nullable|integer|min:1|max:120',
            'endpoint_url'          => 'required|url|max:500',
            'backup_endpoint_url'   => 'nullable|url|max:500',
            'currency'              => 'nullable|string|size:3',
            'supported_cabins'      => 'nullable|array',
            'supported_operations'  => 'nullable|array',
            'timeout_ms'            => 'nullable|integer|min:500',
            // Optional credentials
            'client_id'             => 'nullable|string|max:255',
            'client_secret'         => 'nullable|string',
            'api_key'               => 'nullable|string',
            'office_id'             => 'nullable|string|max:100',
        ]);

        $supplier = FlightSupplier::create([
            'tenant_id'        => auth()->user()?->tenantId(),
            'code'             => strtoupper($data['code']),
            'name'             => $data['name'],
            'type'             => $data['type'],
            'adapter_class'    => $data['adapter_class'],
            'environment'      => $data['environment'] ?? 'sandbox',
            'health_status'    => SupplierHealthStatus::ACTIVE->value,
            'is_active'        => $data['is_active'] ?? true,
            'priority'         => $data['priority'] ?? 0,
            'timeout_seconds'  => $data['timeout_seconds'] ?? 15,
        ]);

        FlightSupplierConfiguration::create([
            'flight_supplier_id'   => $supplier->id,
            'tenant_id'            => $supplier->tenant_id,
            'endpoint_url'         => $data['endpoint_url'],
            'backup_endpoint_url'  => $data['backup_endpoint_url'] ?? null,
            'currency'             => $data['currency'] ?? 'INR',
            'supported_cabins'     => $data['supported_cabins'] ?? FlightCabinClass::query()->where('is_active', true)->orderBy('sort_order')->pluck('code')->unique()->values()->all(),
            'supported_operations' => $data['supported_operations'] ?? ['search', 'price', 'hold', 'book', 'ticket', 'cancel', 'refund'],
            'timeout_ms'           => $data['timeout_ms'] ?? 10000,
        ]);

        if (! empty($data['client_id']) || ! empty($data['api_key']) || ! empty($data['client_secret'])) {
            FlightSupplierCredential::create([
                'flight_supplier_id' => $supplier->id,
                'tenant_id'          => $supplier->tenant_id,
                'client_id'          => $data['client_id'] ?? null,
                'client_secret'      => $data['client_secret'] ?? null,
                'api_key'            => $data['api_key'] ?? null,
                'office_id'          => $data['office_id'] ?? null,
            ]);
        }

        return ApiResponse::created($supplier->load(['configuration', 'credentials']));
    }

    /**
     * Show a supplier.
     *
     * GET /flight/suppliers/{id}
     */
    public function show(int $id): JsonResponse
    {
        $supplier = FlightSupplier::with(['configuration', 'credentials', 'mappings', 'healthLogs' => fn ($q) => $q->latest()->limit(10)])
            ->findOrFail($id);

        return ApiResponse::success($supplier);
    }

    /**
     * Update supplier and configuration.
     *
     * PUT /flight/suppliers/{id}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $supplier = FlightSupplier::findOrFail($id);

        $data = $request->validate([
            'name'                  => 'sometimes|string|max:150',
            'type'                  => 'sometimes|in:gds,ndc,airline_api,consolidator,manual,contracted',
            'environment'           => 'sometimes|in:sandbox,production,test',
            'is_active'             => 'sometimes|boolean',
            'priority'              => 'sometimes|integer',
            'timeout_seconds'       => 'sometimes|integer|min:1|max:120',
            'endpoint_url'          => 'sometimes|url|max:500',
            'backup_endpoint_url'   => 'nullable|url|max:500',
            'currency'              => 'sometimes|string|size:3',
            'supported_cabins'      => 'nullable|array',
            'supported_operations'  => 'nullable|array',
        ]);

        $supplier->update(array_filter([
            'name'            => $data['name'] ?? null,
            'type'            => $data['type'] ?? null,
            'environment'     => $data['environment'] ?? null,
            'is_active'       => $data['is_active'] ?? null,
            'priority'        => $data['priority'] ?? null,
            'timeout_seconds' => $data['timeout_seconds'] ?? null,
        ], fn ($v) => ! is_null($v)));

        if ($supplier->configuration) {
            $supplier->configuration->update(array_filter([
                'endpoint_url'         => $data['endpoint_url'] ?? null,
                'backup_endpoint_url'  => $data['backup_endpoint_url'] ?? null,
                'currency'             => $data['currency'] ?? null,
                'supported_cabins'     => $data['supported_cabins'] ?? null,
                'supported_operations' => $data['supported_operations'] ?? null,
            ], fn ($v) => ! is_null($v)));
        }

        return ApiResponse::success($supplier->fresh(['configuration', 'credentials']));
    }

    /**
     * Update encrypted credentials.
     *
     * POST /flight/suppliers/{id}/credentials
     */
    public function updateCredentials(Request $request, int $id): JsonResponse
    {
        $supplier = FlightSupplier::findOrFail($id);

        $data = $request->validate([
            'client_id'              => 'nullable|string|max:255',
            'client_secret'          => 'nullable|string',
            'api_key'                => 'nullable|string',
            'auth_token'             => 'nullable|string',
            'office_id'              => 'nullable|string|max:100',
            'corporate_code'         => 'nullable|string|max:100',
            'additional_credentials' => 'nullable|array',
            'token_expires_at'       => 'nullable|date',
        ]);

        $credentials = FlightSupplierCredential::updateOrCreate(
            ['flight_supplier_id' => $supplier->id],
            [
                'tenant_id'              => $supplier->tenant_id,
                'client_id'              => $data['client_id'] ?? null,
                'client_secret'          => $data['client_secret'] ?? null,
                'api_key'                => $data['api_key'] ?? null,
                'auth_token'             => $data['auth_token'] ?? null,
                'office_id'              => $data['office_id'] ?? null,
                'corporate_code'         => $data['corporate_code'] ?? null,
                'additional_credentials' => $data['additional_credentials'] ?? null,
                'token_expires_at'       => $data['token_expires_at'] ?? null,
            ]
        );

        return ApiResponse::success([
            'message'            => 'Credentials updated securely.',
            'flight_supplier_id' => $supplier->id,
            'has_client_secret'  => ! empty($credentials->client_secret),
            'has_api_key'        => ! empty($credentials->api_key),
            'has_auth_token'     => ! empty($credentials->auth_token),
            'office_id'          => $credentials->office_id,
        ]);
    }

    /**
     * Trigger on-demand health check.
     *
     * POST /flight/suppliers/{id}/health-check
     */
    public function healthCheck(int $id): JsonResponse
    {
        $supplier = FlightSupplier::findOrFail($id);
        $adapter = $this->supplierManager->supplier($supplier);

        $result = $this->healthService->checkHealth($supplier, $adapter);

        return ApiResponse::success([
            'supplier_code' => $result->supplierCode,
            'health_status' => $result->status->value,
            'latency_ms'    => $result->latencyMs,
            'is_healthy'    => $result->isHealthy,
            'error_message' => $result->errorMessage,
        ]);
    }

    /**
     * List entity mappings for a supplier.
     *
     * GET /flight/suppliers/{id}/mappings
     */
    public function mappings(Request $request, int $id): JsonResponse
    {
        $query = FlightSupplierMapping::where('flight_supplier_id', $id);

        if ($type = $request->query('mapping_type')) {
            $query->where('mapping_type', $type);
        }

        $perPage = $request->integer('per_page', 30);

        return ApiResponse::pagination($query->paginate($perPage));
    }

    /**
     * Store or update a supplier mapping.
     *
     * POST /flight/suppliers/{id}/mappings
     */
    public function storeMapping(Request $request, int $id): JsonResponse
    {
        $supplier = FlightSupplier::findOrFail($id);

        $data = $request->validate([
            'mapping_type'           => 'required|in:airline,flight,fare,inventory,reference,pnr,ticket',
            'supplier_code'          => 'required|string|max:100',
            'internal_id'            => 'nullable|integer',
            'internal_code'          => 'nullable|string|max:100',
            'supplier_reference'     => 'nullable|string|max:255',
            'supplier_pnr'           => 'nullable|string|max:50',
            'supplier_ticket_number' => 'nullable|string|max:50',
            'metadata'               => 'nullable|array',
        ]);

        $mapping = FlightSupplierMapping::updateOrCreate(
            [
                'flight_supplier_id' => $supplier->id,
                'mapping_type'       => $data['mapping_type'],
                'supplier_code'      => $data['supplier_code'],
            ],
            [
                'tenant_id'              => $supplier->tenant_id,
                'internal_id'            => $data['internal_id'] ?? null,
                'internal_code'          => $data['internal_code'] ?? null,
                'supplier_reference'     => $data['supplier_reference'] ?? null,
                'supplier_pnr'           => isset($data['supplier_pnr']) ? strtoupper($data['supplier_pnr']) : null,
                'supplier_ticket_number' => $data['supplier_ticket_number'] ?? null,
                'metadata'               => $data['metadata'] ?? null,
            ]
        );

        return ApiResponse::created($mapping);
    }
}
