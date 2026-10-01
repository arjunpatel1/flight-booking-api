<?php

namespace Modules\Saas\Http\Controllers\Api\V1;

use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Branch\Models\Branch;
use Modules\Core\Http\Controllers\Controller;
use Modules\Saas\Models\Tenant;
use Modules\Saas\Services\Entitlements\EffectiveTenantEntitlementService;
use Modules\Support\ApiResponse;
use Modules\WhatsAppCenter\Jobs\SyncWhatsAppCatalogProduct;
use Modules\WhatsAppCenter\Models\WhatsAppProviderProfile;
use Modules\WhatsAppCenter\Models\WhatsAppTenantAssignment;
use Modules\WhatsAppCenter\Services\NexMsgConnectionValidator;
use Modules\WhatsAppCenter\Services\WhatsAppBranchTransition;
use Modules\WhatsAppCenter\Services\WhatsAppConnectionDiagnostics;

class SaasWhatsAppOrderingController extends Controller
{
    private const IDENTITY_CHECKS = ['auth_key', 'account_id', 'waba_id', 'display_number'];

    public function __construct(
        private readonly EffectiveTenantEntitlementService $entitlements,
        private readonly NexMsgConnectionValidator $nexMsg,
        private readonly WhatsAppConnectionDiagnostics $diagnostics,
        private readonly WhatsAppBranchTransition $branchTransition,
    ) {}

    /**
     * Identity mismatches are data-entry errors and block the save. Provider
     * readiness gaps (catalog, NexDine Ordering, webhook) are saved and shown
     * as a failed checklist so the admin can finish setup in NexMsg.
     */
    private function assertNexMsgIdentity(string $providerPhoneId, string $displayNumber, array $credentials): void
    {
        $report = $this->nexMsg->report([
            'provider' => 'nexmsg', 'provider_phone_id' => $providerPhoneId,
            'display_number' => $displayNumber, 'credentials' => $credentials,
        ]);
        abort_if($report['reachable'] === false, 503, 'NexMsg could not be reached. Nothing was saved; try again shortly.');
        $errors = collect($report['checks'])
            ->filter(fn ($check) => $check['status'] === 'fail' && in_array($check['key'], self::IDENTITY_CHECKS, true))
            ->mapWithKeys(fn ($check) => [$check['field'] => $check['message']])->all();
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    public function diagnostics(string $profile): JsonResponse
    {
        return ApiResponse::success($this->diagnostics->forProfile($this->profile($profile)));
    }

    private function profile(string $uuid): WhatsAppProviderProfile
    {
        return WhatsAppProviderProfile::query()->withoutGlobalTenant()
            ->where('ownership_mode', 'nexdine_managed')->where('uuid', $uuid)->firstOrFail();
    }

    private function assignment(string $uuid): WhatsAppTenantAssignment
    {
        return WhatsAppTenantAssignment::query()->withoutGlobalTenant()->where('uuid', $uuid)->firstOrFail();
    }

    private function normalizedNumber(string $number): string
    {
        return preg_replace('/\D+/', '', $number) ?: '';
    }

    private function assertNumberAvailable(string $providerPhoneId, string $displayNumber, ?int $exceptPhoneNumberId = null): void
    {
        $normalized = $this->normalizedNumber($displayNumber);
        $conflict = \Modules\WhatsAppCenter\Models\WhatsAppPhoneNumber::query()
            ->when($exceptPhoneNumberId, fn ($query) => $query->whereKeyNot($exceptPhoneNumberId))
            ->get(['id', 'provider_phone_id', 'display_number'])
            ->first(fn ($number) => $number->provider_phone_id === $providerPhoneId
                || ($normalized !== '' && $this->normalizedNumber((string) $number->display_number) === $normalized));

        if ($conflict) {
            throw ValidationException::withMessages([
                'display_number' => 'This WhatsApp number or provider number ID already exists. Edit the existing profile instead of creating another one.',
                'provider_phone_id' => 'This WhatsApp number or provider number ID already exists. Edit the existing profile instead of creating another one.',
            ]);
        }
    }

    private function defaultCapabilities(): array
    {
        return [
            'ordering' => true,
            'approval_mode' => 'manual',
            'enabled_order_types' => ['takeaway'],
            'human_handoff' => true,
            'payments' => false,
            'kot_release_policy' => 'after_payment_or_approval',
            'greeting_keywords' => ['hi', 'hii', 'hiii', 'hey', 'hello', 'start', 'menu'],
            'catalog_message' => 'Welcome to {restaurant}! Tap View catalogue, add the items you want, review your cart, then tap Send to business.',
            'catalog_footer' => 'Tap below to browse the menu',
            'catalog_rejection_message' => 'Some selected items are currently unavailable. Please open the latest catalogue and choose available items, or contact the restaurant.',
        ];
    }

    /**
     * Platform admins control only these switches. Greeting words, messages
     * and approval rules belong to the restaurant and are never overwritten
     * by unvalidated request keys.
     */
    private function platformCapabilities(array $capabilities): array
    {
        return [
            'ordering' => (bool) $capabilities['ordering'],
            'human_handoff' => (bool) $capabilities['human_handoff'],
            'payments' => (bool) $capabilities['payments'],
            'enabled_order_types' => array_values(array_unique($capabilities['enabled_order_types'])),
        ];
    }

    private function tenantReadiness(Tenant $tenant): array
    {
        $subscription = $this->entitlements->subscription($tenant);
        $features = $this->entitlements->features($tenant);
        $branches = Branch::query()->withoutGlobalActive()
            ->where('tenant_id', $tenant->id)->where('is_active', true)
            ->orderBy('name')->get(['uuid', 'name']);
        $issues = [];
        if (! $tenant->is_active) {
            $issues[] = 'Restaurant is inactive.';
        }
        if (! in_array('whatsapp_ordering', $features, true)) {
            $issues[] = 'WhatsApp Ordering is not enabled in the active subscription.';
        }
        if ($branches->isEmpty()) {
            $issues[] = 'Create and activate at least one branch.';
        }

        return [
            'uuid' => $tenant->uuid,
            'name' => $tenant->name,
            'is_active' => (bool) $tenant->is_active,
            'plan' => $subscription?->plan?->name,
            'subscription_status' => $subscription?->status,
            'whatsapp_enabled' => in_array('whatsapp_ordering', $features, true),
            'branches' => $branches,
            'issues' => $issues,
            'ready_for_assignment' => $issues === [],
        ];
    }

    private function operationalReadiness(Tenant $tenant, ?WhatsAppTenantAssignment $assignment, ?object $event): array
    {
        $base = $this->tenantReadiness($tenant);
        $profile = $assignment?->profile;
        $number = $assignment?->phoneNumber;
        $capabilities = (array) ($assignment?->capabilities ?? []);
        $ordering = data_get($capabilities, 'ordering') === true || in_array('ordering', $capabilities, true);
        $selectedBranches = collect($assignment?->allowed_branch_ids ?? [])->map(fn ($id) => (int) $id)->filter()->unique();
        $activeBranches = Branch::query()->withoutGlobalActive()->where('tenant_id', $tenant->id)->where('is_active', true)
            ->when($selectedBranches->isNotEmpty(), fn ($query) => $query->whereIn('id', $selectedBranches))->count();
        $catalogProducts = $assignment ? DB::table('whatsapp_catalog_products')
            ->where('tenant_id', $tenant->id)->where('provider_profile_id', $assignment->provider_profile_id)
            ->when($selectedBranches->isNotEmpty(), fn ($query) => $query->whereIn('branch_id', $selectedBranches))
            ->where('status', 'active')->where('sync_status', 'synced')->count() : 0;
        $checks = [
            ['key' => 'tenant', 'label' => 'Restaurant active', 'status' => $tenant->is_active ? 'pass' : 'fail', 'message' => $tenant->is_active ? 'Restaurant can receive traffic.' : 'Activate this restaurant.'],
            ['key' => 'entitlement', 'label' => 'WhatsApp entitlement', 'status' => $base['whatsapp_enabled'] ? 'pass' : 'fail', 'message' => $base['whatsapp_enabled'] ? 'Included in the active plan.' : 'Enable WhatsApp Ordering on the active plan.'],
            ['key' => 'assignment', 'label' => 'Number assigned and active', 'status' => $assignment?->is_active && $assignment?->suspended_at === null ? 'pass' : 'fail', 'message' => $assignment ? ($assignment->is_active ? 'Assignment is active.' : 'Assignment is suspended; incoming messages are rejected.') : 'Assign a managed number to this restaurant.'],
            ['key' => 'provider', 'label' => 'Provider profile connected', 'status' => $profile?->is_active && $profile?->status === 'connected' ? 'pass' : 'fail', 'message' => $profile ? "Profile status: {$profile->status}." : 'No provider profile is assigned.'],
            ['key' => 'number', 'label' => 'Provider number matches', 'status' => $number?->is_active ? 'pass' : 'fail', 'message' => $number?->is_active ? "{$number->display_number} is active." : 'The assigned provider number is inactive or missing.'],
            ['key' => 'ordering', 'label' => 'Ordering enabled', 'status' => $ordering ? 'pass' : 'fail', 'message' => $ordering ? 'Customer ordering is enabled.' : 'Enable ordering on the assignment.'],
            ['key' => 'branches', 'label' => 'Ordering branches available', 'status' => $activeBranches > 0 && ($selectedBranches->isEmpty() || $activeBranches === $selectedBranches->count()) ? 'pass' : 'fail', 'message' => $activeBranches > 0 ? "{$activeBranches} active branch(es) available." : 'Select at least one active branch.'],
            ['key' => 'catalog', 'label' => 'Catalog products synced', 'status' => $catalogProducts > 0 ? 'pass' : 'fail', 'message' => $catalogProducts > 0 ? "{$catalogProducts} active product(s) are synced for this number." : 'Sync active menu products for this restaurant and provider profile.'],
            ['key' => 'webhook', 'label' => 'Customer webhook received', 'status' => $profile?->webhook_last_received_at ? 'pass' : 'warn', 'message' => $profile?->webhook_last_received_at ? 'Last received '.$profile->webhook_last_received_at->diffForHumans().'.' : 'No signed customer message has reached this profile yet.'],
        ];
        if ($event?->status === 'failed') {
            $checks[] = ['key' => 'last_event', 'label' => 'Latest webhook processed', 'status' => 'fail', 'message' => $event->error ?: 'The latest webhook failed.'];
        } elseif ($event) {
            $checks[] = ['key' => 'last_event', 'label' => 'Latest webhook processed', 'status' => 'pass', 'message' => "Latest {$event->event_type} event was {$event->status}."];
        }
        $failed = collect($checks)->where('status', 'fail')->count();
        $passed = collect($checks)->where('status', 'pass')->count();

        return [
            ...$base,
            'whatsapp' => [
                'status' => $failed > 0 ? 'attention' : ($assignment && $profile?->webhook_last_received_at ? 'ready' : 'waiting'),
                'completed_steps' => $passed,
                'total_steps' => count($checks),
                'assignment_uuid' => $assignment?->uuid,
                'number' => $number?->display_number,
                'profile' => $profile ? ['uuid' => $profile->uuid, 'name' => $profile->name, 'provider' => $profile->provider, 'status' => $profile->status] : null,
                'last_webhook_at' => $profile?->webhook_last_received_at?->toIso8601String(),
                'last_event_at' => $event?->created_at,
                'last_event_status' => $event?->status,
                'last_error' => $event?->error ?: $profile?->last_error,
                'checks' => $checks,
            ],
        ];
    }

    public function index(): JsonResponse
    {
        $profiles = WhatsAppProviderProfile::query()->withoutGlobalTenant()->where('ownership_mode', 'nexdine_managed')
            ->with('phoneNumbers')->latest()->get()->map(fn ($profile) => [
                'uuid' => $profile->uuid,
                'name' => $profile->name,
                'provider' => $profile->provider,
                'status' => $profile->status,
                'is_active' => (bool) $profile->is_active,
                'webhook_last_received_at' => $profile->webhook_last_received_at?->toIso8601String(),
                'validated_at' => $profile->validated_at?->toIso8601String(),
                'last_error' => $profile->last_error,
                // Expose non-secret identifiers and configured flags so the edit
                // screen can show the complete setup without leaking credentials.
                'configuration' => [
                    'business_account_id' => data_get($profile->credentials, 'business_account_id'),
                    'account_id' => data_get($profile->credentials, 'account_id'),
                    'catalog_id' => data_get($profile->credentials, 'catalog_id'),
                    'has_access_token' => filled(data_get($profile->credentials, 'access_token')),
                    'has_auth_key' => filled(data_get($profile->credentials, 'auth_key')),
                    'has_webhook_secret' => filled(data_get($profile->credentials, 'webhook_secret')),
                ],
                'phone_numbers' => $profile->phoneNumbers->map(fn ($number) => [
                    'uuid' => $number->uuid, 'provider_phone_id' => $number->provider_phone_id, 'display_number' => $number->display_number,
                    'display_name' => $number->display_name, 'status' => $number->status,
                ])->values(),
            ])->values();
        $assignments = WhatsAppTenantAssignment::query()->withoutGlobalTenant()
            ->with(['profile', 'phoneNumber'])->latest()->paginate();
        $tenantMap = Tenant::query()->withoutGlobalScopes()->whereIn('id', $assignments->pluck('tenant_id'))
            ->get()->keyBy('id');
        $allAssignments = WhatsAppTenantAssignment::query()->withoutGlobalTenant()->with(['profile', 'phoneNumber'])
            ->orderByDesc('is_active')->orderByDesc('id')->get()->unique('tenant_id')->keyBy('tenant_id');
        $latestEvents = DB::table('whatsapp_webhook_events')->whereIn('id', function ($query) {
            $query->from('whatsapp_webhook_events')->selectRaw('MAX(id)')->groupBy('provider_profile_id');
        })->get()->keyBy('provider_profile_id');

        $tenants = Tenant::query()->withoutGlobalScopes()->whereNull('deleted_at')->orderBy('name')->get()
            ->map(function (Tenant $tenant) use ($allAssignments, $latestEvents) {
                $assignment = $allAssignments->get($tenant->id);
                $event = $assignment ? $latestEvents->get($assignment->provider_profile_id) : null;

                return $this->operationalReadiness($tenant, $assignment, $event);
            })->values();
        $recentEvents = DB::table('whatsapp_webhook_events as e')
            ->leftJoin('tenants as t', 't.id', '=', 'e.tenant_id')
            ->leftJoin('whatsapp_provider_profiles as p', 'p.id', '=', 'e.provider_profile_id')
            ->orderByDesc('e.id')->limit(50)->get([
                'e.id', 'e.event_type', 'e.status', 'e.error', 'e.created_at', 'e.processed_at',
                't.name as tenant_name', 't.slug as tenant_slug', 'p.name as profile_name', 'p.provider',
            ]);

        return ApiResponse::success([
            'profiles' => $profiles,
            'tenants' => $tenants,
            'recent_events' => $recentEvents,
            'assignments' => [
                'data' => $assignments->getCollection()->map(function ($assignment) use ($tenantMap) {
                    $tenant = $tenantMap->get($assignment->tenant_id);

                    return [
                        'uuid' => $assignment->uuid,
                        'tenant' => $tenant ? ['uuid' => $tenant->uuid, 'name' => $tenant->name] : null,
                        'profile' => ['uuid' => $assignment->profile?->uuid, 'name' => $assignment->profile?->name, 'provider' => $assignment->profile?->provider, 'status' => $assignment->profile?->status,
                            'last_error' => $assignment->profile?->last_error, 'webhook_last_received_at' => $assignment->profile?->webhook_last_received_at?->toIso8601String()],
                        'phone_number' => ['uuid' => $assignment->phoneNumber?->uuid, 'display_number' => $assignment->phoneNumber?->display_number],
                        'is_active' => (bool) $assignment->is_active,
                        'capabilities' => $assignment->capabilities ?? [],
                        'allowed_branch_ids' => Branch::query()->withoutGlobalActive()
                            ->where('tenant_id', $assignment->tenant_id)
                            ->whereIn('id', $assignment->allowed_branch_ids ?? [])
                            ->pluck('uuid')->values(),
                        'available_branches' => Branch::query()->withoutGlobalActive()
                            ->where('tenant_id', $assignment->tenant_id)->where('is_active', true)
                            ->orderBy('name')->get(['uuid', 'name']),
                        'monthly_message_limit' => $assignment->monthly_message_limit,
                        'monthly_order_limit' => $assignment->monthly_order_limit,
                    ];
                })->values(),
                'current_page' => $assignments->currentPage(), 'last_page' => $assignments->lastPage(),
                'per_page' => $assignments->perPage(), 'total' => $assignments->total(),
            ],
        ]);
    }

    public function storeProfile(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'], 'provider' => ['required', Rule::in(['meta', 'msg91', 'nexmsg'])],
            'provider_phone_id' => ['required', 'string', 'max:191'], 'display_number' => ['required', 'string', 'max:40'],
            'credentials' => ['required', 'array'], 'credentials.webhook_secret' => ['required', 'string', 'min:16', 'max:4096'],
            'credentials.access_token' => ['required_if:provider,meta', 'nullable', 'string', 'max:4096'],
            'credentials.business_account_id' => ['required_if:provider,meta', 'nullable', 'string', 'max:191'],
            'credentials.auth_key' => [Rule::requiredIf(fn () => in_array($request->input('provider'), ['msg91', 'nexmsg'], true)), 'nullable', 'string', 'max:4096'],
            'credentials.account_id' => ['required_if:provider,nexmsg', 'nullable', 'string', 'max:191',
                Rule::when($request->input('provider') === 'nexmsg', ['regex:/^[a-f0-9]{24}$/i'])],
            'credentials.catalog_id' => ['required_if:provider,nexmsg', 'nullable', 'string', 'max:191'],
        ], [
            'credentials.account_id.regex' => 'Use the 24-character NexMsg Account ID, not the numeric WABA ID.',
        ]);
        $this->assertNumberAvailable($data['provider_phone_id'], $data['display_number']);
        if ($data['provider'] === 'nexmsg') {
            $this->assertNexMsgIdentity($data['provider_phone_id'], $data['display_number'], $data['credentials']);
        }
        $profile = DB::transaction(function () use ($data) {
            $profile = WhatsAppProviderProfile::query()->withoutGlobalTenant()->create([
                'tenant_id' => null, 'name' => $data['name'], 'ownership_mode' => 'nexdine_managed',
                'provider' => $data['provider'], 'credentials' => $data['credentials'], 'status' => 'pending',
            ]);
            $profile->phoneNumbers()->create(['provider_phone_id' => $data['provider_phone_id'], 'display_number' => $data['display_number'], 'status' => 'pending']);

            return $profile->load('phoneNumbers');
        });
        $diagnostics = $this->diagnostics->forProfile($profile);
        $profile->refresh();

        return ApiResponse::success([
            'uuid' => $profile->uuid, 'name' => $profile->name, 'provider' => $profile->provider,
            'status' => $profile->status,
            'phone_numbers' => $profile->phoneNumbers->map(fn ($number) => [
                'uuid' => $number->uuid, 'display_number' => $number->display_number, 'status' => $number->status,
            ])->values(),
            'diagnostics' => $diagnostics,
        ], code: 201);
    }

    public function updateProfile(Request $request, string $profile): JsonResponse
    {
        $model = $this->profile($profile);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'display_number' => ['required', 'string', 'max:40'],
            'provider_phone_id' => ['required', 'string', 'max:191'],
            // Connection health is derived from diagnostics and real webhooks;
            // an admin can only disable or re-enable a profile.
            'status' => ['nullable', Rule::in(['pending', 'connected', 'failed', 'disabled'])],
            'is_active' => ['required', 'boolean'],
            'credentials' => ['nullable', 'array'],
            'credentials.webhook_secret' => ['nullable', 'string', 'min:16', 'max:4096'],
            'credentials.access_token' => ['nullable', 'string', 'max:4096'],
            'credentials.auth_key' => ['nullable', 'string', 'max:4096'],
            'credentials.business_account_id' => ['nullable', 'string', 'max:191'],
            'credentials.account_id' => ['nullable', 'string', 'max:191'],
            'credentials.catalog_id' => ['nullable', 'string', 'max:191'],
        ]);
        $this->assertNumberAvailable(
            $data['provider_phone_id'],
            $data['display_number'],
            $model->phoneNumbers()->value('id'),
        );
        if ($model->provider === 'nexmsg') {
            $this->assertNexMsgIdentity($data['provider_phone_id'], $data['display_number'],
                array_replace((array) $model->credentials, array_filter($data['credentials'] ?? [], fn ($value) => filled($value))));
        }
        $status = match (true) {
            ($data['status'] ?? null) === 'disabled' || ! $data['is_active'] => 'disabled',
            $model->status === 'disabled' => 'pending',
            default => $model->status,
        };
        DB::transaction(function () use ($model, $data, $status): void {
            $changes = ['name' => $data['name'], 'status' => $status, 'is_active' => $data['is_active']];
            if (! empty($data['credentials'])) {
                $changes['credentials'] = array_replace((array) $model->credentials, array_filter($data['credentials'], fn ($value) => filled($value)));
                $changes['credential_version'] = 'v'.((int) ltrim((string) $model->credential_version, 'v') + 1);
            }
            $model->update($changes);
            $model->phoneNumbers()->firstOrFail()->update([
                'provider_phone_id' => $data['provider_phone_id'], 'display_number' => $data['display_number'],
                'is_active' => $data['is_active'],
            ]);
        });

        return ApiResponse::success(['diagnostics' => $this->diagnostics->forProfile($model->refresh())], 'Managed provider profile updated.');
    }

    public function destroyProfile(string $profile): JsonResponse
    {
        $model = $this->profile($profile);
        abort_if(WhatsAppTenantAssignment::query()->withoutGlobalTenant()->where('provider_profile_id', $model->id)->exists(), 409,
            'Remove this profile’s restaurant assignments before deleting it.');
        $model->delete();

        return ApiResponse::success(null, 'Managed provider profile deleted.');
    }

    public function assign(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tenant_id' => ['required', 'string', 'max:64'], 'profile_id' => ['required', 'string', 'max:64'],
            'phone_number_id' => ['required', 'string', 'max:64'], 'allowed_branch_ids' => ['nullable', 'array'],
            'allowed_branch_ids.*' => ['string', 'max:64'], 'capabilities' => ['required', 'array'],
            'capabilities.ordering' => ['required', 'boolean'],
            'capabilities.human_handoff' => ['required', 'boolean'],
            'capabilities.payments' => ['required', 'boolean'],
            'capabilities.enabled_order_types' => ['required', 'array', 'min:1'],
            'capabilities.enabled_order_types.*' => [Rule::in(['takeaway', 'delivery'])],
            'monthly_message_limit' => ['nullable', 'integer', 'min:0'], 'monthly_order_limit' => ['nullable', 'integer', 'min:0'],
        ]);
        $profile = WhatsAppProviderProfile::query()->withoutGlobalTenant()->where('ownership_mode', 'nexdine_managed')
            ->where(fn ($query) => $query->where('uuid', $data['profile_id'])->when(ctype_digit($data['profile_id']), fn ($q) => $q->orWhereKey((int) $data['profile_id'])))->firstOrFail();
        $number = $profile->phoneNumbers()->where(fn ($query) => $query->where('uuid', $data['phone_number_id'])->when(ctype_digit($data['phone_number_id']), fn ($q) => $q->orWhereKey((int) $data['phone_number_id'])))->firstOrFail();
        $tenant = Tenant::query()->withoutGlobalScopes()->whereNull('deleted_at')->where(fn ($query) => $query->where('uuid', $data['tenant_id'])->when(ctype_digit($data['tenant_id']), fn ($q) => $q->orWhereKey((int) $data['tenant_id'])))->firstOrFail();
        $readiness = $this->tenantReadiness($tenant);
        if (! $readiness['ready_for_assignment']) {
            throw ValidationException::withMessages([
                'tenant_id' => implode(' ', $readiness['issues']),
            ]);
        }
        $branchKeys = collect($data['allowed_branch_ids'] ?? [])->unique()->values();
        $branchIds = Branch::query()->withoutGlobalActive()->where('tenant_id', $tenant->id)
            ->where(function ($query) use ($branchKeys) {
                $query->whereIn('uuid', $branchKeys);
                $numeric = $branchKeys->filter(fn ($key) => ctype_digit((string) $key))->map(fn ($key) => (int) $key);
                if ($numeric->isNotEmpty()) {
                    $query->orWhereIn('id', $numeric);
                }
            })->pluck('id');
        abort_unless($branchIds->count() === $branchKeys->count(), 404);
        $normalized = $this->normalizedNumber((string) $number->display_number);
        $conflict = WhatsAppTenantAssignment::query()->withoutGlobalTenant()
            ->with(['phoneNumber', 'profile'])->where('tenant_id', '!=', $tenant->id)->get()
            ->first(fn ($assignment) => (int) $assignment->phone_number_id === (int) $number->id
                || ($normalized !== '' && $this->normalizedNumber((string) $assignment->phoneNumber?->display_number) === $normalized));
        if ($conflict) {
            $conflictTenant = Tenant::query()->withoutGlobalScopes()->find($conflict->tenant_id)?->name ?: 'another restaurant';
            throw ValidationException::withMessages([
                'phone_number_id' => "This WhatsApp number is already assigned to {$conflictTenant}. Remove that assignment before using it for another restaurant.",
            ]);
        }
        try {
            $assignment = DB::transaction(function () use ($data, $tenant, $profile, $number, $branchIds) {
                WhatsAppTenantAssignment::query()->withoutGlobalTenant()->where('tenant_id', $tenant->id)->update(['is_active' => false, 'suspended_at' => now()]);

                return WhatsAppTenantAssignment::query()->withoutGlobalTenant()->updateOrCreate([
                    'tenant_id' => $tenant->id, 'phone_number_id' => $number->id,
                ], [
                    'tenant_id' => $tenant->id, 'provider_profile_id' => $profile->id, 'phone_number_id' => $number->id,
                    'ownership_mode' => 'nexdine_managed', 'allowed_branch_ids' => $branchIds->all(),
                    'capabilities' => array_replace($this->defaultCapabilities(), $this->platformCapabilities($data['capabilities'])),
                    'monthly_message_limit' => $data['monthly_message_limit'] ?? null, 'monthly_order_limit' => $data['monthly_order_limit'] ?? null,
                    'is_active' => true,
                ]);
            });
        } catch (QueryException $exception) {
            if (in_array((string) $exception->getCode(), ['23000', '23505'], true)) {
                throw ValidationException::withMessages([
                    'phone_number_id' => 'This WhatsApp number was assigned by another request. Refresh and select an available number.',
                ]);
            }
            throw $exception;
        }

        return ApiResponse::success(['uuid' => $assignment->uuid, 'is_active' => (bool) $assignment->is_active], code: 201);
    }

    public function transferAssignment(Request $request, string $assignment): JsonResponse
    {
        $model = $this->assignment($assignment)->load(['profile', 'phoneNumber']);
        $data = $request->validate([
            'tenant_id' => ['required', 'string', 'max:64'],
            'allowed_branch_ids' => ['nullable', 'array'],
            'allowed_branch_ids.*' => ['string', 'max:64'],
            'confirmation' => ['required', 'string', 'in:TRANSFER'],
        ]);
        $tenant = Tenant::query()->withoutGlobalScopes()->whereNull('deleted_at')
            ->where(fn ($query) => $query->where('uuid', $data['tenant_id'])->when(ctype_digit($data['tenant_id']), fn ($q) => $q->orWhereKey((int) $data['tenant_id'])))->firstOrFail();
        if ((int) $tenant->id === (int) $model->tenant_id) {
            throw ValidationException::withMessages(['tenant_id' => 'Select a different restaurant for this transfer.']);
        }
        $readiness = $this->tenantReadiness($tenant);
        if (! $readiness['ready_for_assignment']) {
            throw ValidationException::withMessages(['tenant_id' => implode(' ', $readiness['issues'])]);
        }
        $branchKeys = collect($data['allowed_branch_ids'] ?? [])->unique()->values();
        $branchIds = Branch::query()->withoutGlobalActive()->where('tenant_id', $tenant->id)
            ->where(function ($query) use ($branchKeys) {
                $query->whereIn('uuid', $branchKeys);
                $numeric = $branchKeys->filter(fn ($key) => ctype_digit((string) $key))->map(fn ($key) => (int) $key);
                if ($numeric->isNotEmpty()) {
                    $query->orWhereIn('id', $numeric);
                }
            })->pluck('id');
        abort_unless($branchIds->count() === $branchKeys->count(), 422, 'One or more selected branches are unavailable.');
        $sourceTenant = Tenant::query()->withoutGlobalScopes()->find($model->tenant_id);

        DB::transaction(function () use ($model, $tenant, $branchIds): void {
            $locked = WhatsAppTenantAssignment::query()->withoutGlobalTenant()->lockForUpdate()->findOrFail($model->id);
            DB::table('whatsapp_conversations')->where('assignment_id', $locked->id)->whereNull('closed_at')
                ->update(['state' => 'closed', 'closed_at' => now(), 'updated_at' => now()]);
            $destination = WhatsAppTenantAssignment::query()->withoutGlobalTenant()->with('profile')
                ->where('tenant_id', $tenant->id)->whereKeyNot($locked->id)->where('is_active', true)->first();
            if ($destination && (int) $destination->provider_profile_id !== (int) $locked->provider_profile_id) {
                $locked->loadMissing('profile');
                $sourceCatalog = (string) data_get($locked->profile?->credentials, 'catalog_id');
                $destinationCatalog = (string) data_get($destination->profile?->credentials, 'catalog_id');
                if ($sourceCatalog !== '' && hash_equals($sourceCatalog, $destinationCatalog)) {
                    $destinationMappings = DB::table('whatsapp_catalog_products')->where('tenant_id', $tenant->id)
                        ->where('provider_profile_id', $destination->provider_profile_id)->get(['product_id', 'product_retailer_id']);
                    $hasConflict = $destinationMappings->isNotEmpty() && DB::table('whatsapp_catalog_products')
                        ->where('provider_profile_id', $locked->provider_profile_id)->where('catalog_id', $sourceCatalog)
                        ->where(function ($query) use ($destinationMappings): void {
                            $query->whereIn('product_id', $destinationMappings->pluck('product_id'))
                                ->orWhereIn('product_retailer_id', $destinationMappings->pluck('product_retailer_id'));
                        })->exists();
                    if ($hasConflict) {
                        throw ValidationException::withMessages([
                            'tenant_id' => 'Catalog mappings conflict with the destination restaurant. Remove stale mappings or re-sync the catalog before transferring this number.',
                        ]);
                    }
                    DB::table('whatsapp_catalog_products')->where('tenant_id', $tenant->id)
                        ->where('provider_profile_id', $destination->provider_profile_id)
                        ->update(['provider_profile_id' => $locked->provider_profile_id, 'updated_at' => now()]);
                }
            }
            WhatsAppTenantAssignment::query()->withoutGlobalTenant()->where('tenant_id', $tenant->id)
                ->whereKeyNot($locked->id)->update(['is_active' => false, 'suspended_at' => now(), 'updated_at' => now()]);
            $locked->update([
                'tenant_id' => $tenant->id,
                'allowed_branch_ids' => $branchIds->all(),
                'is_active' => true,
                'suspended_at' => null,
            ]);
        });

        activity('whatsapp_assignment')->event('transferred')->causedBy($request->user())
            ->withProperties([
                'assignment_uuid' => $model->uuid,
                'number' => $model->phoneNumber?->display_number,
                'from_tenant' => $sourceTenant?->name,
                'to_tenant' => $tenant->name,
            ])->log('Managed WhatsApp number transferred between restaurants.');
        $this->diagnostics->forProfile($model->profile->refresh());

        return ApiResponse::success(null, "WhatsApp number transferred to {$tenant->name}. Existing conversations were safely closed.");
    }

    public function updateAssignment(Request $request, string $assignment): JsonResponse
    {
        $model = $this->assignment($assignment);
        $data = $request->validate([
            'monthly_message_limit' => ['required', 'integer', 'min:1'],
            'monthly_order_limit' => ['required', 'integer', 'min:1'],
            'is_active' => ['required', 'boolean'],
            'allowed_branch_ids' => ['nullable', 'array'],
            'allowed_branch_ids.*' => ['string', 'max:64'],
            'capabilities' => ['required', 'array'],
            'capabilities.ordering' => ['required', 'boolean'],
            'capabilities.human_handoff' => ['required', 'boolean'],
            'capabilities.payments' => ['required', 'boolean'],
            'capabilities.enabled_order_types' => ['required', 'array', 'min:1'],
            'capabilities.enabled_order_types.*' => [Rule::in(['takeaway', 'delivery'])],
        ]);
        $branchKeys = collect($data['allowed_branch_ids'] ?? [])->unique()->values();
        $branchIds = Branch::query()->withoutGlobalActive()->where('tenant_id', $model->tenant_id)
            ->where('is_active', true)
            ->where(function ($query) use ($branchKeys) {
                $query->whereIn('uuid', $branchKeys);
                $numeric = $branchKeys->filter(fn ($key) => ctype_digit((string) $key))->map(fn ($key) => (int) $key);
                if ($numeric->isNotEmpty()) {
                    $query->orWhereIn('id', $numeric);
                }
            })->pluck('id');
        abort_unless($branchIds->count() === $branchKeys->count(), 422, 'One or more selected branches are unavailable.');
        $transition = DB::transaction(function () use ($model, $data, $branchIds): array {
            $lockedAssignment = WhatsAppTenantAssignment::query()->withoutGlobalTenant()
                ->whereKey($model->id)->lockForUpdate()->firstOrFail();
            $transition = $this->branchTransition->retireRemovedBranches($lockedAssignment, $branchIds->all());
            if ($data['is_active']) {
                WhatsAppTenantAssignment::query()->withoutGlobalTenant()->where('tenant_id', $model->tenant_id)
                    ->where('id', '!=', $model->id)->update(['is_active' => false, 'suspended_at' => now()]);
            }
            $lockedAssignment->update([
                'monthly_message_limit' => $data['monthly_message_limit'],
                'monthly_order_limit' => $data['monthly_order_limit'],
                'is_active' => $data['is_active'],
                'allowed_branch_ids' => $branchIds->all(),
                'capabilities' => array_replace($this->defaultCapabilities(), (array) $lockedAssignment->capabilities, $this->platformCapabilities($data['capabilities'])),
                'suspended_at' => $data['is_active'] ? null : now(),
            ]);

            return $transition;
        });
        foreach ($transition['catalog_mapping_ids'] as $mappingId) {
            SyncWhatsAppCatalogProduct::dispatch($mappingId);
        }

        $message = 'Restaurant assignment updated.';
        if ($transition['removed_branch_ids'] !== []) {
            $message .= ' Old-branch chats were closed and '.count($transition['catalog_mapping_ids']).' catalog product(s) are being removed. Sync the new branch catalog before accepting orders.';
        }

        return ApiResponse::success(null, $message);
    }

    public function destroyAssignment(string $assignment): JsonResponse
    {
        $model = $this->assignment($assignment);
        abort_if(DB::table('whatsapp_conversations')->where('assignment_id', $model->id)->exists(), 409,
            'This assignment has message history. Disable it instead of deleting it.');
        $model->delete();

        return ApiResponse::success(null, 'Restaurant assignment deleted.');
    }
}
