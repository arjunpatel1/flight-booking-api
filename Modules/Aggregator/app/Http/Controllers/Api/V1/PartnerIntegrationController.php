<?php

namespace Modules\Aggregator\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Modules\Aggregator\Models\PartnerApiCredential;
use Modules\Aggregator\Models\PartnerApiIntegration;
use Modules\Aggregator\Services\PartnerApi\PartnerCredentialService;
use Modules\Branch\Models\Branch;
use Modules\Core\Http\Controllers\Controller;
use Modules\Saas\Models\Tenant;
use Modules\Saas\Services\Entitlements\EffectiveTenantEntitlementService;
use Modules\Support\ApiResponse;

class PartnerIntegrationController extends Controller
{
    public function __construct(
        private readonly PartnerCredentialService $credentials,
        private readonly EffectiveTenantEntitlementService $entitlements,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $partners = PartnerApiIntegration::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->with(['credentials' => fn ($query) => $query->select([
                'id', 'uuid', 'partner_id', 'api_key', 'scopes', 'branch_ids', 'ip_allowlist',
                'requests_per_minute', 'orders_per_minute', 'status', 'expires_at',
                'burst_limit',
                'grace_expires_at', 'last_used_at', 'created_at',
            ])])
            ->latest()->paginate(min(100, max(1, $request->integer('per_page', 25))));

        return ApiResponse::pagination($partners);
    }

    public function meta(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);

        return ApiResponse::success([
            'branches' => Branch::query()->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Branch $branch) => ['value' => $branch->id, 'title' => $branch->name])
                ->values(),
            'scopes' => array_values(array_filter([
                ['value' => 'catalog:read', 'title' => 'Read catalog', 'description' => 'View permitted branches, menus and opaque product references.'],
                ['value' => 'orders:read', 'title' => 'Read orders', 'description' => 'View only orders owned by this tenant integration.'],
                ['value' => 'orders:write', 'title' => 'Create and cancel orders', 'description' => 'Create server-priced orders and cancel eligible orders.'],
                ['value' => 'orders:status', 'title' => 'Update order status', 'description' => 'Advance orders through valid workflow steps.'],
                ['value' => 'payments:write', 'title' => 'Record collected payments', 'description' => 'Collect the server-calculated outstanding balance.'],
                $this->hasDelivery($tenantId) ? ['value' => 'deliveries:read', 'title' => 'Read delivery tasks', 'description' => 'Read delivery tasks owned by this integration.'] : null,
                $this->hasDelivery($tenantId) ? ['value' => 'deliveries:status', 'title' => 'Update driver milestones', 'description' => 'Advance owned delivery tasks through the forward-only driver workflow.'] : null,
            ])),
            'environments' => [
                ['value' => 'sandbox', 'title' => 'Sandbox'],
                ['value' => 'production', 'title' => 'Production'],
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules());
        $tenantId = $this->tenantId($request);
        $this->assertDeliveryScopesAllowed($tenantId, (array) ($data['scopes'] ?? []));
        $requestedBranches = array_map('intval', $data['branch_ids'] ?? []);
        abort_unless(
            empty($requestedBranches) || Branch::query()->withoutGlobalScopes()->where('tenant_id', $tenantId)->whereIn('id', $requestedBranches)->count() === count(array_unique($requestedBranches)),
            422,
            'One or more selected branches do not belong to this restaurant.',
        );
        $issued = $this->credentials->createIntegration($tenantId, $data);

        return ApiResponse::created(body: $this->issuedPayload($issued), resource: 'Partner integration')
            ->withHeaders(['Cache-Control' => 'no-store, private', 'Pragma' => 'no-cache']);
    }

    public function rotate(Request $request, string $credentialUuid): JsonResponse
    {
        $credential = $this->credential($request, $credentialUuid);
        $issued = $this->credentials->rotate($credential);

        return ApiResponse::success($this->issuedPayload($issued))
            ->withHeaders(['Cache-Control' => 'no-store, private', 'Pragma' => 'no-cache']);
    }

    public function revoke(Request $request, string $credentialUuid): JsonResponse
    {
        $credential = $this->credential($request, $credentialUuid);
        $credential->update(['status' => 'revoked', 'expires_at' => now(), 'grace_expires_at' => null]);

        return ApiResponse::success(['uuid' => $credential->uuid, 'status' => 'revoked']);
    }

    public function update(Request $request, string $credentialUuid): JsonResponse
    {
        $credential = $this->credential($request, $credentialUuid);
        abort_if($credential->status === 'revoked', 409, 'A revoked credential cannot be edited.');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => ['string', Rule::in($this->allowedScopes())],
            'branch_ids' => ['nullable', 'array'],
            'branch_ids.*' => ['integer'],
            'ip_allowlist' => ['nullable', 'array'],
            'ip_allowlist.*' => $this->ipRule(),
            'requests_per_minute' => ['required', 'integer', 'min:1', 'max:10000'],
            'orders_per_minute' => ['required', 'integer', 'min:1', 'max:1000'],
            'burst_limit' => ['required', 'integer', 'min:1', 'max:1000'],
        ]);
        $tenantId = $this->tenantId($request);
        $requestedBranches = array_values(array_unique(array_map('intval', $data['branch_ids'] ?? [])));
        abort_unless(
            $requestedBranches === [] || Branch::query()->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)->where('is_active', true)
                ->whereIn('id', $requestedBranches)->count() === count($requestedBranches),
            422,
            'One or more selected branches do not belong to this restaurant.',
        );
        $scopes = array_values(array_unique($data['scopes']));
        $this->assertDeliveryScopesAllowed($tenantId, $scopes);
        if (in_array('orders:write', $scopes, true) && ! in_array('orders:read', $scopes, true)) {
            $scopes[] = 'orders:read';
        }

        DB::transaction(function () use ($credential, $data, $requestedBranches, $scopes) {
            $credential->partner()->update(['name' => trim($data['name'])]);
            $credential->update([
                'scopes' => $scopes,
                'branch_ids' => $requestedBranches,
                'ip_allowlist' => array_values(array_unique($data['ip_allowlist'] ?? [])),
                'requests_per_minute' => $data['requests_per_minute'],
                'orders_per_minute' => $data['orders_per_minute'],
                'burst_limit' => $data['burst_limit'],
            ]);
        });

        return ApiResponse::success([
            'partner' => $credential->partner->fresh()->only(['uuid', 'name', 'environment', 'status']),
            'credential' => $credential->fresh()->only([
                'uuid', 'api_key', 'scopes', 'branch_ids', 'ip_allowlist',
                'requests_per_minute', 'orders_per_minute', 'burst_limit', 'status',
            ]),
        ], message: 'Partner integration updated successfully.');
    }

    private function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],
            'environment' => ['sometimes', Rule::in(['sandbox', 'production'])],
            'scopes' => ['sometimes', 'array', 'min:1'],
            'scopes.*' => ['string', Rule::in($this->allowedScopes())],
            'branch_ids' => ['nullable', 'array'],
            'branch_ids.*' => ['integer'],
            'ip_allowlist' => ['nullable', 'array'],
            'ip_allowlist.*' => $this->ipRule(),
            'requests_per_minute' => ['sometimes', 'integer', 'min:1', 'max:10000'],
            'orders_per_minute' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'burst_limit' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'metadata' => ['nullable', 'array'],
        ];
    }

    private function ipRule(): array
    {
        return ['string', 'max:64', function (string $attribute, mixed $value, \Closure $fail) {
            [$address, $prefix] = array_pad(explode('/', (string) $value, 2), 2, null);
            if (! filter_var($address, FILTER_VALIDATE_IP)) {
                $fail("The {$attribute} must contain a valid IP address or CIDR range.");

                return;
            }
            if ($prefix !== null) {
                $max = str_contains($address, ':') ? 128 : 32;
                if (! ctype_digit($prefix) || (int) $prefix < 0 || (int) $prefix > $max) {
                    $fail("The {$attribute} CIDR prefix is invalid.");
                }
            }
        }];
    }

    private function allowedScopes(): array
    {
        return ['catalog:read', 'orders:read', 'orders:write', 'orders:status', 'payments:write',
            'deliveries:read', 'deliveries:status', 'webhooks:manage'];
    }

    private function assertDeliveryScopesAllowed(int $tenantId, array $scopes): void
    {
        if (array_intersect(['deliveries:read', 'deliveries:status'], $scopes)) {
            abort_unless($this->hasDelivery($tenantId), 422,
                'Third-party delivery access must be assigned and active before delivery API scopes can be granted.');
        }
    }

    private function hasDelivery(int $tenantId): bool
    {
        $tenant = Tenant::query()->withoutGlobalScopes()->find($tenantId);

        return $tenant !== null && $this->entitlements->has($tenant, 'delivery');
    }

    private function credential(Request $request, string $uuid): PartnerApiCredential
    {
        return PartnerApiCredential::query()->withoutGlobalScopes()
            ->with('partner')->where('tenant_id', $this->tenantId($request))->where('uuid', $uuid)->firstOrFail();
    }

    private function tenantId(Request $request): int
    {
        $tenantId = $request->user()?->tenant_id;
        abort_unless($tenantId, 422, 'The current account is not assigned to a restaurant.');

        return (int) $tenantId;
    }

    private function issuedPayload(array $issued): array
    {
        $credential = $issued['credential'];

        return [
            'partner' => isset($issued['partner']) ? $issued['partner']->only(['uuid', 'name', 'environment', 'status']) : null,
            'credential' => [
                'uuid' => $credential->uuid,
                'api_key' => $credential->api_key,
                'api_secret' => $issued['secret'],
                'scopes' => $credential->scopes,
                'branch_ids' => $credential->branch_ids,
            ],
            'warning' => 'Copy the API secret now. It will not be shown again.',
        ];
    }
}
