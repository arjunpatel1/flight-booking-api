<?php

namespace Modules\Saas\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Modules\Notification\Enums\NotificationChannel;
use Modules\Notification\Services\NotificationDispatcherService;
use Modules\Notification\Services\NotificationServiceInterface;
use Modules\Branch\Models\Branch;
use Modules\Core\Http\Controllers\Controller;
use Modules\Order\Models\Order;
use Modules\Payment\Models\Payment;
use Modules\Saas\Http\Requests\Api\V1\ConfigureApacheSslRequest;
use Modules\Saas\Http\Requests\Api\V1\ProvisionTenantRequest;
use Modules\Saas\Models\SaasActivationKey;
use Modules\Saas\Models\SaasBillingInvoice;
use Modules\Saas\Models\SaasCoupon;
use Modules\Saas\Models\SaasDeliveryJob;
use Modules\Saas\Models\SaasProvisioningRun;
use Modules\Saas\Models\SubscriptionPlan;
use Modules\Saas\Models\Tenant;
use Modules\Saas\Models\TenantSubscription;
use Modules\Saas\Services\Billing\SaasBillingService;
use Modules\Saas\Services\Billing\SaasLicenseLifecycleService;
use Modules\Saas\Services\Operations\SaasRealtimeIncidentService;
use Modules\Saas\Services\Provisioning\ProvisioningOrchestratorService;
use Modules\Saas\Services\Provisioning\SaasHealthService;
use Modules\Saas\Services\Provisioning\SaasProvisioningService;
use Modules\Saas\Services\Provisioning\SaasServerAutomationService;
use Modules\Saas\Services\Provisioning\SaasTenantLifecycleService;
use Modules\Saas\Transformers\Api\V1\TenantResource;
use Modules\Support\ApiResponse;
use Modules\User\Enums\DefaultRole;
use Modules\User\Models\Role;
use Modules\Support\InputLimit;
use Modules\User\Models\Permission;
use Modules\User\Models\User;
use Symfony\Component\HttpFoundation\Response;

class SaasOperationsController extends Controller
{
    public function dashboard(
        SaasHealthService $health,
        ProvisioningOrchestratorService $orchestrator,
        SaasRealtimeIncidentService $realtimeIncidents
    ): JsonResponse
    {
        // Keep every executive counter on the same tenant visibility contract as
        // the Restaurant Registry. Bypassing all scopes here made archived or
        // otherwise invisible restaurants appear only in dashboard totals.
        $tenants = Tenant::query()->withoutGlobalActive()->get();
        $tenantIds = $tenants->modelKeys();
        $subscriptions = Schema::hasTable('tenant_subscriptions')
            ? TenantSubscription::query()
                ->withoutGlobalScopes()
                ->whereIn('tenant_id', $tenantIds)
                ->with('plan:id,name')
                ->get()
                ->groupBy('tenant_id')
                ->map(fn ($rows) => $rows->sortByDesc('id')->first())
                ->values()
            : collect();

        $healthRows = collect($health->all())
            ->filter(fn (array $row) => ! isset($row['tenant_id']) || in_array($row['tenant_id'], $tenantIds, true))
            ->values();
        $unhealthy = $healthRows->filter(fn (array $row) => $row['database'] !== 'ok' || $row['storage'] !== 'ok')->count();
        $billing = $this->billingSummary();
        $executive = $this->executiveAnalytics($tenants, $subscriptions);

        return ApiResponse::success([
            'generated_at' => now()->toIso8601String(),
            'summary' => [
                'tenants' => $tenants->count(),
                'active_tenants' => $tenants->where('is_active', true)->count(),
                'suspended_tenants' => $tenants->where('is_active', false)->count(),
                'deleted_tenants' => Tenant::query()->withoutGlobalActive()->onlyTrashed()->count(),
                'plans' => Schema::hasTable('subscription_plans')
                    ? SubscriptionPlan::query()->withoutGlobalScopes()->count()
                    : 0,
                'subscriptions' => $subscriptions->count(),
                'trial_tenants' => $subscriptions->where('status', 'trial')->count(),
                'past_due_subscriptions' => $subscriptions->where('status', 'past_due')->count(),
                'unhealthy_tenants' => $unhealthy,
                'new_today' => $tenants->where('created_at', '>=', now()->startOfDay())->count(),
                'new_this_month' => $tenants->where('created_at', '>=', now()->startOfMonth())->count(),
                'onboarding_pending' => $this->onboardingPendingCount($tenantIds),
                'expiring_plans' => $executive['expiring_plans'],
                'customer_growth_rate' => $executive['customer_growth_rate'],
                'churn_rate' => $executive['churn_rate'],
                'retention_rate' => $executive['retention_rate'],
            ],
            'billing' => [...$billing, 'collection_rate' => $executive['collection_rate']],
            'executive' => $executive,
            'licenses' => $this->licenseSummary(),
            'analytics' => $this->tenantAnalytics(),
            'health' => $healthRows->take(20)->values(),
            'alerts' => $this->alerts($healthRows, $realtimeIncidents),
            'provisioning' => $this->provisioningSummary($orchestrator),
        ]);
    }

    public function provision(
        ProvisionTenantRequest $request,
        SaasProvisioningService $service,
        ProvisioningOrchestratorService $orchestrator
    ): JsonResponse {
        $result = $service->provision($request->validated());

        return ApiResponse::created([
            'tenant' => new TenantResource($result['tenant']),
            'branch' => $result['branch'],
            'admin' => $result['admin']->only(['id', 'name', 'email', 'username']),
            'staff' => $result['staff']?->only(['id', 'name', 'email', 'username']),
            'subscription' => $result['subscription'],
            'provisioning' => $this->provisioningPayload($result['provisioning_run'], $orchestrator),
            'delivery_jobs' => collect($result['delivery_jobs'] ?? [])->map(fn (SaasDeliveryJob $job) => $this->deliveryPayload($job))->values(),
            'urls' => $result['urls'],
            'password' => $result['password'],
        ], __('saas::tenants.tenant'));
    }

    public function domainCheck(Request $request): JsonResponse
    {
        $domain = $this->normalizeTenantDomain((string) $request->input('domain', ''));

        $request->merge(['domain' => $domain]);
        $data = $request->validate([
            'domain' => [
                'required',
                'string',
                'max:255',
                'regex:/^(?!-)(?:[a-z0-9-]{1,63}\.)+[a-z]{2,63}$/',
                Rule::unique('tenants', 'domain'),
            ],
        ], [
            'domain.regex' => 'Enter a valid domain only, for example redison-blu.nexdine.myteknoland.net. Do not include https://, paths, spaces, or uppercase branding text.',
        ]);

        $domain = $data['domain'];
        $records = $this->resolveTenantDomain($domain);
        $expectedIps = array_values(array_filter((array) config('saas.tenant_server_ips', [])));
        $pointsToServer = empty($expectedIps)
            ? null
            : count(array_intersect($records, $expectedIps)) > 0;

        return ApiResponse::success([
            'domain' => $domain,
            'valid' => true,
            'resolves' => ! empty($records),
            'records' => $records,
            'expected_ips' => $expectedIps,
            'points_to_server' => $pointsToServer,
            'status' => empty($records)
                ? 'not_resolving'
                : ($pointsToServer === false ? 'wrong_server' : 'ready'),
            'message' => empty($records)
                ? 'Domain format is valid, but DNS is not resolving yet.'
                : ($pointsToServer === false
                    ? 'Domain resolves, but it is not pointing to the NexDine tenant server.'
                    : 'Domain is valid and ready to use.'),
        ]);
    }

    public function provisioningStatus(string $uuid, ProvisioningOrchestratorService $orchestrator): JsonResponse
    {
        $run = SaasProvisioningRun::query()
            ->with(['tenant:id,name,slug,domain', 'branch:id,name'])
            ->where('uuid', $uuid)
            ->firstOrFail();

        return ApiResponse::success($this->provisioningPayload($run, $orchestrator));
    }

    public function syncTenantPermissions(Tenant $tenant): JsonResponse
    {
        Artisan::call('permission:sync-permissions');
        Artisan::call('permission:sync-default-roles', ['--force' => true]);

        return ApiResponse::success([
            'tenant_id' => $tenant->id,
            ...$this->tenantAccessCatalog($tenant),
            'synced_at' => now()->toIso8601String(),
        ]);
    }

    public function tenantAccess(Tenant $tenant): JsonResponse
    {
        return ApiResponse::success([
            'tenant_id' => $tenant->id,
            ...$this->tenantAccessCatalog($tenant),
        ]);
    }

    public function assignTenantUserRole(Request $request, Tenant $tenant, int $user): JsonResponse
    {
        $tenantUser = User::query()->withoutGlobalScopes()->findOrFail($user);
        abort_unless((int) $tenantUser->tenant_id === (int) $tenant->id, 404);

        $requestedRoles = $request->has('roles')
            ? $request->input('roles')
            : [$request->input('role')];
        $request->merge(['roles' => $requestedRoles]);
        $data = $request->validate([
            'roles' => ['required', 'array', 'min:1', 'max:5'],
            'roles.*' => ['required', 'string', 'distinct', Rule::in(DefaultRole::getBranchAvailableRoles())],
            'branch_id' => [
                'nullable',
                'integer',
                Rule::exists('branches', 'id')->where(fn ($query) => $query->where('tenant_id', $tenant->id)),
            ],
        ]);

        $enterpriseRoleSelected = in_array(DefaultRole::EnterpriseAdmin->value, $data['roles'], true);
        abort_if(
            $enterpriseRoleSelected && count($data['roles']) > 1,
            422,
            'Restaurant super administrator is a tenant-wide profile and cannot be combined with branch roles.'
        );

        $roles = Role::query()
            ->whereIn('name', $data['roles'])
            ->get();
        abort_unless($roles->count() === count($data['roles']), 422, 'One or more restaurant roles are unavailable. Synchronize permissions and try again.');

        $branchId = null;
        if (! $enterpriseRoleSelected) {
            $branchId = $data['branch_id'] ?? $tenantUser->branch_id;
            if ($branchId === null) {
                $tenantBranches = Branch::query()
                    ->where('tenant_id', $tenant->id)
                    ->where('is_active', true)
                    ->pluck('id');
                abort_unless(
                    $tenantBranches->count() === 1,
                    422,
                    'Select a restaurant branch before assigning branch-scoped roles.'
                );
                $branchId = $tenantBranches->first();
            }
        }

        $tenantUser->syncRoles($roles);
        if ((int) $tenantUser->branch_id !== (int) $branchId || ($branchId === null && $tenantUser->branch_id !== null)) {
            $tenantUser->forceFill(['branch_id' => $branchId])->save();
        }
        $revokedSessions = $tenantUser->tokens()->delete();
        $tenantUser->unsetRelation('roles');

        return ApiResponse::success([
            'user' => $tenantUser->fresh('roles:id,name')->only(['id', 'name', 'email', 'username', 'tenant_id', 'branch_id']),
            'roles' => $roles->map->only(['id', 'name'])->values(),
            'sessions_revoked' => $revokedSessions,
        ]);
    }

    public function completeOnboarding(Request $request, Tenant $tenant, SaasProvisioningService $service, ProvisioningOrchestratorService $orchestrator): JsonResponse
    {
        if ($request->filled('domain')) {
            $request->merge(['domain' => $this->normalizeTenantDomain((string) $request->input('domain'))]);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'], 'legal_name' => ['nullable', 'string', 'max:255'],
            'domain' => [
                'required',
                'string',
                'max:255',
                'regex:/^(?!-)(?:[a-z0-9-]{1,63}\.)+[a-z]{2,63}$/',
                Rule::unique('tenants', 'domain')->ignore($tenant->id),
            ],
            'email' => ['required', 'email', 'max:255'], 'phone' => ['nullable', 'string', 'max:30'],
            'admin_name' => ['required', 'string', 'max:255'], 'password' => ['nullable', 'string', 'min:8', 'max:255'],
            'plan' => ['required', 'string', 'max:120'], 'trial_days' => ['required', 'integer', 'min:0', 'max:365'],
            'branch_name' => ['required', 'string', 'max:255'], 'legal_name' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'], 'city' => ['nullable', 'string', 'max:120'],
            'state' => ['nullable', 'string', 'max:120'], 'postal_code' => ['nullable', 'string', 'max:30'],
            'gst_number' => ['nullable', 'string', 'max:40'], 'currency' => ['required', 'string', 'size:3'],
            'timezone' => ['required', 'string', 'max:80'],
        ], [
            'domain.regex' => 'Enter a valid domain only, for example redison-blu.nexdine.myteknoland.net. Do not include https://, paths, spaces, or uppercase branding text.',
        ]);
        $result = $service->complete($tenant, $data);

        return ApiResponse::success([
            'tenant' => new TenantResource($result['tenant']),
            'subscription' => $result['subscription'],
            'provisioning' => $this->provisioningPayload($result['provisioning_run'], $orchestrator),
        ], 'Restaurant onboarding started.');
    }

    public function retryProvisioning(Request $request, string $uuid, ProvisioningOrchestratorService $orchestrator): JsonResponse
    {
        $validated = $request->validate(['step' => ['nullable', 'string', 'max:100']]);

        return ApiResponse::updated(
            $this->provisioningPayload($orchestrator->retry($uuid, $validated['step'] ?? null), $orchestrator),
            'Provisioning retry queued.'
        );
    }

    public function resumeProvisioning(string $uuid, ProvisioningOrchestratorService $orchestrator): JsonResponse
    {
        return ApiResponse::updated(
            $this->provisioningPayload($orchestrator->resume($uuid), $orchestrator),
            'Provisioning resumed.'
        );
    }

    public function cancelProvisioning(Request $request, string $uuid, ProvisioningOrchestratorService $orchestrator): JsonResponse
    {
        $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        return ApiResponse::updated(
            $this->provisioningPayload($orchestrator->cancel($uuid, $request->input('reason', 'Cancelled by admin.')), $orchestrator),
            'Provisioning cancelled.'
        );
    }

    public function deliveryStatus(string $uuid): JsonResponse
    {
        $job = SaasDeliveryJob::query()
            ->with('tenant:id,name,slug,domain')
            ->where('uuid', $uuid)
            ->firstOrFail();

        return ApiResponse::success($this->deliveryPayload($job));
    }

    public function activate(int $tenant, SaasTenantLifecycleService $service): JsonResponse
    {
        return ApiResponse::updated(
            new TenantResource($service->activate($this->tenant($tenant))),
            __('saas::tenants.tenant')
        );
    }

    public function suspend(Request $request, int $tenant, SaasTenantLifecycleService $service): JsonResponse
    {
        $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        return ApiResponse::updated(
            new TenantResource($service->suspend($this->tenant($tenant), $request->input('reason', 'admin'))),
            __('saas::tenants.tenant')
        );
    }

    public function backup(int $tenant, SaasTenantLifecycleService $service): JsonResponse
    {
        return ApiResponse::success([
            'path' => $service->backup($this->tenant($tenant)),
        ], __('saas::operations.backup_created'));
    }

    public function sendTenantMessage(Request $request, int $tenant, NotificationDispatcherService $notifications): JsonResponse
    {
        $tenantModel = $this->tenant($tenant);
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'message' => ['required', 'string', 'max:2000'],
            'template' => ['nullable', 'string', 'max:80'],
            'priority' => ['nullable', 'string', 'in:low,normal,warning,critical'],
            'channels' => ['nullable', 'array'],
            'channels.*' => ['string', 'in:in_app,email,whatsapp,push'],
        ]);

        $logs = $this->dispatchTenantMessage($tenantModel, $validated, $notifications);

        return ApiResponse::success([
            'status' => 'queued',
            'notification_logs' => $logs->pluck('id')->values()->all(),
        ], 'Tenant message queued.');
    }

    public function sendTenantMessages(Request $request, NotificationDispatcherService $notifications): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'message' => ['required', 'string', 'max:2000'],
            'template' => ['nullable', 'string', 'max:80'],
            'priority' => ['nullable', 'string', 'in:low,normal,warning,critical'],
            'channels' => ['nullable', 'array'],
            'channels.*' => ['string', 'in:in_app,email,whatsapp,push'],
            'all' => ['nullable', 'boolean'],
            'tenant_ids' => ['nullable', 'array'],
            'tenant_ids.*' => ['integer', 'exists:tenants,id'],
        ]);

        $sendToAll = (bool) ($validated['all'] ?? false);
        $tenantIds = collect($validated['tenant_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        abort_unless($sendToAll || count($tenantIds) > 0, 422, 'Select one or more restaurants.');

        $tenants = Tenant::query()
            ->withoutGlobalScopes()
            ->withoutGlobalActive()
            ->when(! $sendToAll, fn ($query) => $query->whereIn('id', $tenantIds))
            ->get();

        $logs = $tenants->flatMap(
            fn (Tenant $tenant) => $this->dispatchTenantMessage($tenant, $validated, $notifications)
        );

        return ApiResponse::success([
            'status' => 'queued',
            'tenants' => $tenants->pluck('id')->values()->all(),
            'notification_logs' => $logs->pluck('id')->values()->all(),
        ], 'Tenant messages queued.');
    }

    public function bulkAssignPlan(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tenant_ids' => ['required', 'array', 'min:1', 'max:100'], 'tenant_ids.*' => ['integer', 'exists:tenants,id'],
            'subscription_plan_id' => ['required', 'integer', 'exists:subscription_plans,id'],
        ]);

        $updated = DB::transaction(function () use ($validated, $request) {
            return collect($validated['tenant_ids'])->map(function (int $tenantId) use ($validated, $request) {
                $subscription = TenantSubscription::query()->withoutGlobalScopes()->where('tenant_id', $tenantId)
                    ->whereIn('status', ['trial', 'active', 'past_due'])->latest()->first();
                if ($subscription) $subscription->update(['subscription_plan_id' => $validated['subscription_plan_id']]);
                else $subscription = TenantSubscription::query()->withoutGlobalScopes()->create(['tenant_id' => $tenantId, 'subscription_plan_id' => $validated['subscription_plan_id'], 'status' => 'active', 'starts_at' => now()]);
                activity('saas_subscription')->event('plan_assigned')->causedBy($request->user())->performedOn($subscription)
                    ->withProperties(['tenant_id' => $tenantId, 'subscription_plan_id' => $validated['subscription_plan_id']])->log('Subscription plan assigned from Restaurant Registry.');
                return $tenantId;
            })->all();
        });

        return ApiResponse::success(['tenant_ids' => $updated], 'Plan assigned to selected restaurants.');
    }

    public function bulkExtendTrial(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tenant_ids' => ['required', 'array', 'min:1', 'max:100'], 'tenant_ids.*' => ['integer', 'exists:tenants,id'],
            'days' => ['required', 'integer', 'min:1', 'max:365'],
        ]);
        $updated = [];
        DB::transaction(function () use ($validated, $request, &$updated) {
            foreach ($validated['tenant_ids'] as $tenantId) {
                $subscription = TenantSubscription::query()->withoutGlobalScopes()->where('tenant_id', $tenantId)->whereIn('status', ['trial', 'active'])->latest()->first();
                if (! $subscription) continue;
                $base = $subscription->trial_ends_at?->isFuture() ? $subscription->trial_ends_at->copy() : now();
                $subscription->update(['status' => 'trial', 'trial_ends_at' => $base->addDays($validated['days'])]);
                activity('saas_subscription')->event('trial_extended')->causedBy($request->user())->performedOn($subscription)
                    ->withProperties(['tenant_id' => $tenantId, 'days' => $validated['days']])->log('Trial extended from Restaurant Registry.');
                $updated[] = $tenantId;
            }
        });
        return ApiResponse::success(['tenant_ids' => $updated, 'skipped' => count($validated['tenant_ids']) - count($updated)], 'Trials extended for eligible restaurants.');
    }

    public function bulkCreateInvoices(Request $request, SaasBillingService $billing): JsonResponse
    {
        $validated = $request->validate([
            'tenant_ids' => ['required', 'array', 'min:1', 'max:100'], 'tenant_ids.*' => ['integer', 'exists:tenants,id'],
            'amount' => ['nullable', ...InputLimit::money()],
        ]);
        $invoices = [];
        foreach ($validated['tenant_ids'] as $tenantId) {
            $subscription = TenantSubscription::query()->withoutGlobalScopes()->with(['tenant', 'plan'])->where('tenant_id', $tenantId)->whereIn('status', ['trial', 'active', 'past_due'])->latest()->first();
            if ($subscription) $invoices[] = $billing->createInvoice($subscription, $validated['amount'] ?? null)->id;
        }
        return ApiResponse::created(['invoice_ids' => $invoices, 'skipped' => count($validated['tenant_ids']) - count($invoices)], 'Invoices created for eligible restaurants.');
    }

    public function bulkApplyCoupon(Request $request, SaasLicenseLifecycleService $licenses): JsonResponse
    {
        $validated = $request->validate([
            'tenant_ids' => ['required', 'array', 'min:1', 'max:100'],
            'tenant_ids.*' => ['integer', 'exists:tenants,id'],
            'code' => ['required', 'string', 'max:100'],
        ]);
        $applied = [];
        $skipped = [];

        foreach ($validated['tenant_ids'] as $tenantId) {
            $subscription = TenantSubscription::query()->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)->whereIn('status', ['trial', 'active', 'past_due'])->latest()->first();
            if (! $subscription) {
                $skipped[] = ['tenant_id' => $tenantId, 'reason' => 'No eligible subscription.'];
                continue;
            }
            try {
                $licenses->applyCoupon($subscription, $validated['code']);
                $applied[] = $tenantId;
            } catch (\Throwable $exception) {
                report($exception);
                $skipped[] = ['tenant_id' => $tenantId, 'reason' => $exception->getMessage()];
            }
        }

        return ApiResponse::success(['tenant_ids' => $applied, 'skipped' => $skipped], 'Coupon processing completed.');
    }

    public function bulkArchive(Request $request, SaasTenantLifecycleService $lifecycle): JsonResponse
    {
        $validated = $request->validate([
            'tenant_ids' => ['required', 'array', 'min:1', 'max:100'],
            'tenant_ids.*' => ['integer', 'exists:tenants,id'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
            'confirmation' => ['required', 'string', 'in:ARCHIVE'],
        ]);
        $archived = [];

        DB::transaction(function () use ($validated, $request, $lifecycle, &$archived) {
            $tenants = Tenant::query()->withoutGlobalScopes()->withoutGlobalActive()
                ->whereIn('id', $validated['tenant_ids'])->lockForUpdate()->get();
            foreach ($tenants as $tenant) {
                $backup = $lifecycle->backup($tenant);
                $lifecycle->delete($tenant, false, $validated['reason']);
                activity('saas_tenant_lifecycle')->event('tenant_archived')->causedBy($request->user())->performedOn($tenant)
                    ->withProperties(['reason' => $validated['reason'], 'backup' => $backup])->log('Restaurant archived after a safety backup.');
                $archived[] = ['tenant_id' => $tenant->id, 'backup' => $backup];
            }
        });

        return ApiResponse::success(['archived' => $archived], 'Selected restaurants were backed up and archived.');
    }

    public function exportTenants(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tenant_ids' => ['nullable', 'array', 'max:500'],
            'tenant_ids.*' => ['integer'],
            'include_archived' => ['nullable', 'boolean'],
        ]);
        $rows = Tenant::query()->withoutGlobalScopes()->withoutGlobalActive()
            ->when((bool) ($validated['include_archived'] ?? false), fn ($query) => $query->withTrashed())
            ->with('activeSubscription.plan:id,name')->when(! empty($validated['tenant_ids']), fn ($query) => $query->whereIn('id', $validated['tenant_ids']))
            ->orderBy('name')->get()->map(fn (Tenant $tenant) => [
                'id' => $tenant->id, 'name' => $tenant->name, 'slug' => $tenant->slug, 'domain' => $tenant->domain,
                'contact_email' => $tenant->contact_email, 'contact_phone' => $tenant->contact_phone,
                'status' => $tenant->trashed() ? 'archived' : ($tenant->is_active ? 'active' : 'suspended'),
                'plan' => $tenant->activeSubscription?->plan?->name,
                'subscription_status' => $tenant->activeSubscription?->status,
                'created_at' => $tenant->created_at?->toIso8601String(),
            ])->values();

        return ApiResponse::success(['generated_at' => now()->toIso8601String(), 'rows' => $rows], 'Restaurant export generated.');
    }

    public function restore(Request $request, SaasTenantLifecycleService $service): JsonResponse
    {
        $request->validate(['path' => ['required', 'string', 'max:1000']]);

        return ApiResponse::success($service->restore($request->string('path')->toString()));
    }

    public function executeRestore(Request $request, SaasTenantLifecycleService $service): JsonResponse
    {
        $validated = $request->validate([
            'path' => ['required', 'string', 'max:1000'],
            'confirmation' => ['required', 'string', 'in:RESTORE'],
        ]);

        return ApiResponse::success($service->executeRestore($validated['path'], $validated['confirmation']));
    }

    public function createBillingInvoice(Request $request, SaasBillingService $billing): JsonResponse
    {
        $validated = $request->validate([
            'tenant_subscription_id' => ['required', 'integer', 'exists:tenant_subscriptions,id'],
            'amount' => ['nullable', ...InputLimit::money()],
        ]);

        $subscription = TenantSubscription::query()
            ->withoutGlobalScopes()
            ->with(['tenant', 'plan'])
            ->findOrFail($validated['tenant_subscription_id']);

        return ApiResponse::created([
            'invoice' => $billing->createInvoice($subscription, $validated['amount'] ?? null),
        ], 'SaaS invoice created.');
    }

    public function createBillingPaymentIntent(Request $request, int $invoice, SaasBillingService $billing): JsonResponse
    {
        $validated = $request->validate([
            'gateway' => ['required', 'string', 'in:razorpay,stripe'],
        ]);

        $invoiceModel = SaasBillingInvoice::query()->withoutGlobalScopes()->findOrFail($invoice);

        return ApiResponse::success([
            'invoice' => $billing->createPaymentIntent($invoiceModel, $validated['gateway']),
        ], 'SaaS payment request created.');
    }

    public function downloadBillingInvoice(int $invoice): Response
    {
        $row = SaasBillingInvoice::query()->withoutGlobalScopes()->with('tenant:id,name')->findOrFail($invoice);
        $html = '<!doctype html><html><head><meta charset="utf-8"><title>Invoice '.e($row->invoice_number).'</title><style>body{font:15px system-ui;max-width:760px;margin:40px auto;color:#172033}h1{color:#f57c00}dl{display:grid;grid-template-columns:180px 1fr;gap:12px}dt{color:#667085}dd{margin:0;font-weight:600}</style></head><body><h1>NexDine Invoice</h1><dl><dt>Restaurant</dt><dd>'.e($row->tenant?->name).'</dd><dt>Invoice</dt><dd>'.e($row->invoice_number).'</dd><dt>Amount</dt><dd>'.e($row->currency).' '.e($row->amount).'</dd><dt>Status</dt><dd>'.e($row->status).'</dd><dt>Issued</dt><dd>'.e($row->issued_at?->toDateString()).'</dd><dt>Due</dt><dd>'.e($row->due_at?->toDateString()).'</dd><dt>Paid</dt><dd>'.e($row->paid_at?->toDateString() ?: '—').'</dd></dl></body></html>';
        if (data_get($row->metadata, 'service_feature')) {
            $details = '<dt>Service</dt><dd>'.e(data_get($row->metadata, 'service_feature')).'</dd><dt>Service starts</dt><dd>'.e(data_get($row->metadata, 'service_starts_at')).'</dd><dt>Service expires</dt><dd>'.e(data_get($row->metadata, 'service_ends_at')).'</dd>';
            $html = str_replace('</dl>', $details.'</dl>', $html);
        }

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="invoice-'.preg_replace('/[^A-Za-z0-9_-]/', '-', $row->invoice_number).'.html"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function markBillingPaid(Request $request, int $invoice, SaasBillingService $billing): JsonResponse
    {
        $validated = $request->validate([
            'gateway_reference' => ['nullable', 'string', 'max:255'],
            'payload' => ['nullable', 'array'],
        ]);

        $invoiceModel = SaasBillingInvoice::query()->withoutGlobalScopes()->findOrFail($invoice);

        if (data_get($invoiceModel->metadata, 'service_feature')) {
            $request->validate(['gateway_reference' => ['required','string','max:255']]);
            $validated['recorded_by'] = $request->user()->id;
        }

        return ApiResponse::updated([
            'invoice' => $billing->markPaid($invoiceModel, $validated),
        ], 'SaaS invoice marked paid.');
    }

    public function refundBillingInvoice(Request $request, int $invoice, SaasBillingService $billing): JsonResponse
    {
        $data=$request->validate(['amount'=>['required','numeric','min:0.01'],'reason'=>['required','string','min:5','max:500'],'gateway_reference'=>['nullable','string','max:255']]);
        $model=SaasBillingInvoice::query()->withoutGlobalScopes()->findOrFail($invoice); $refund=$billing->refund($model,(float)$data['amount'],$data['reason'],$request->user()?->id,$data['gateway_reference']??null);
        activity('saas_billing')->event('refund_processed')->causedBy($request->user())->performedOn($model)->withProperties(['refund_id'=>$refund->id,'amount'=>$refund->amount,'reason'=>$refund->reason])->log('SaaS invoice refund recorded.');
        return ApiResponse::created($refund,'Refund recorded.');
    }

    public function billingTaxReport(Request $request, SaasBillingService $billing): JsonResponse
    { $data=$request->validate(['from'=>['nullable','date'],'to'=>['nullable','date','after_or_equal:from']]); return ApiResponse::success($billing->taxReport($data['from']??null,$data['to']??null)); }

    public function runDunning(SaasBillingService $billing): JsonResponse
    {
        return ApiResponse::success([
            'processed' => $billing->runDunning(),
        ], 'SaaS dunning completed.');
    }

    public function createCoupon(Request $request, SaasLicenseLifecycleService $licenses): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:80'],
            'name' => ['nullable', 'string', 'max:255'],
            'type' => ['required', 'string', 'in:flat,percentage,free_months,trial_extension,plan_upgrade'],
            'value' => ['nullable', 'numeric', 'min:0'],
            'free_months' => ['nullable', 'integer', 'min:0', 'max:120'],
            'trial_extension_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'plan_upgrade_id' => ['nullable', 'integer', 'exists:subscription_plans,id'],
            'lifetime' => ['nullable', 'boolean'],
            'usage_limit' => ['nullable', 'integer', 'min:1'],
            'per_tenant_limit' => ['nullable', 'integer', 'min:1'],
            'per_email_limit' => ['nullable', 'integer', 'min:1'],
            'per_mobile_limit' => ['nullable', 'integer', 'min:1'],
            'minimum_plan_id' => ['nullable', 'integer', 'exists:subscription_plans,id'],
            'maximum_discount' => ['nullable', 'numeric', 'min:0'],
            'source' => ['nullable', 'string', 'in:admin,referral,partner'],
            'starts_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date'],
            'is_active' => ['nullable', 'boolean'],
            'metadata' => ['nullable', 'array'],
        ]);

        return ApiResponse::created([
            'coupon' => $licenses->createCoupon($validated),
        ], 'SaaS coupon created.');
    }

    public function applyCoupon(Request $request, SaasLicenseLifecycleService $licenses): JsonResponse
    {
        $validated = $request->validate([
            'tenant_subscription_id' => ['required', 'integer', 'exists:tenant_subscriptions,id'],
            'code' => ['required', 'string', 'max:80'],
            'email' => ['nullable', 'email', 'max:255'],
            'mobile' => ['nullable', 'string', 'max:30'],
        ]);

        $subscription = TenantSubscription::query()
            ->withoutGlobalScopes()
            ->with(['tenant', 'plan'])
            ->findOrFail($validated['tenant_subscription_id']);

        return ApiResponse::updated($licenses->applyCoupon($subscription, $validated['code'], $validated), 'SaaS coupon applied.');
    }

    public function createActivationKey(Request $request, SaasLicenseLifecycleService $licenses): JsonResponse
    {
        $validated = $request->validate([
            'key' => ['nullable', 'string', 'max:80', 'unique:saas_activation_keys,key'],
            'name' => ['nullable', 'string', 'max:255'],
            'subscription_plan_id' => ['nullable', 'integer', 'exists:subscription_plans,id'],
            'tenant_id' => ['nullable', 'integer', 'exists:tenants,id'],
            'partner' => ['nullable', 'string', 'max:255'],
            'activation_limit' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'offline_allowed' => ['nullable', 'boolean'],
            'status' => ['nullable', 'string', 'in:active,revoked,expired'],
            'expires_at' => ['nullable', 'date'],
            'metadata' => ['nullable', 'array'],
        ]);

        return ApiResponse::created([
            'activation_key' => $licenses->createActivationKey($validated),
        ], 'SaaS activation key created.');
    }

    public function activateLicense(Request $request, SaasLicenseLifecycleService $licenses): JsonResponse
    {
        $validated = $request->validate([
            'key' => ['required', 'string', 'max:80'],
            'tenant_id' => ['required', 'integer', 'exists:tenants,id'],
            'device_id' => ['nullable', 'string', 'max:255'],
            'offline' => ['nullable', 'boolean'],
        ]);

        $tenant = Tenant::query()->withoutGlobalScopes()->withoutGlobalActive()->findOrFail($validated['tenant_id']);

        return ApiResponse::updated([
            'activation_key' => $licenses->activateKey($validated['key'], $tenant, $validated),
        ], 'SaaS license activated.');
    }

    public function runBillingLifecycle(SaasLicenseLifecycleService $licenses): JsonResponse
    {
        return ApiResponse::success($licenses->runLifecycle(), 'SaaS billing lifecycle completed.');
    }

    public function dispatchAlerts(
        SaasHealthService $health,
        NotificationDispatcherService $notifications,
        SaasRealtimeIncidentService $realtimeIncidents
    ): JsonResponse
    {
        $recipient = config('saas.alerts.recipient');
        abort_unless($recipient, 422, 'Configure SAAS_ALERT_RECIPIENT before dispatching SaaS alerts.');

        $alerts = $this->alerts(collect($health->all()), $realtimeIncidents);
        $channels = $this->alertChannels();

        $logs = collect($alerts)->flatMap(fn (array $alert) => $notifications->dispatch(
            'saas_platform_alert',
            $recipient,
            [
                'title' => 'NexDine SaaS platform alert',
                'message' => "{$alert['tenant']}: {$alert['message']}",
                'severity' => $alert['severity'],
                'tenant_id' => $alert['tenant_id'],
            ],
            $channels
        ));

        return ApiResponse::success([
            'alerts' => $alerts,
            'notification_logs' => $logs->pluck('id')->all(),
        ], 'SaaS alerts dispatched.');
    }

    public function apacheSsl(ConfigureApacheSslRequest $request, SaasServerAutomationService $service): JsonResponse
    {
        $result = $service->configureApacheSsl($request->validated());

        return $result['successful']
            ? ApiResponse::success($result, $result['applied'] ? 'Apache and SSL configuration applied.' : 'Apache and SSL preview generated.')
            : ApiResponse::errors($result, 'Apache and SSL automation failed.', 422);
    }

    private function tenantAccessCatalog(Tenant $tenant): array
    {
        $tenant->loadMissing('activeSubscription.plan');
        $allPermissions = Permission::query()
            ->orderBy('name')
            ->pluck('name')
            ->values();
        $roles = Role::query()
            ->whereIn('name', collect(DefaultRole::cases())->pluck('value'))
            ->with(['permissions' => fn ($query) => $query->orderBy('name')])
            ->get()
            ->keyBy('name');
        $assignable = collect(DefaultRole::getBranchAvailableRoles());
        $descriptions = [
            DefaultRole::SuperAdmin->value => 'Global platform owner with unrestricted SaaS and restaurant access.',
            DefaultRole::Admin->value => 'Global platform administrator for shared operations and configuration.',
            DefaultRole::EnterpriseAdmin->value => 'Restaurant super administrator with access across every branch in this tenant.',
            DefaultRole::AdminBranch->value => 'Restaurant administrator with complete tenant and branch management.',
            DefaultRole::Manager->value => 'Restaurant operations, team supervision, reporting, and approvals.',
            DefaultRole::Cashier->value => 'Registers, billing, payments, invoices, and cash operations.',
            DefaultRole::Kitchen->value => 'Kitchen display, preparation workflow, and order status operations.',
            DefaultRole::Waiter->value => 'Tables, orders, payments, and waiter application workflows.',
            DefaultRole::Customer->value => 'Customer application identity without restaurant administration access.',
        ];

        $roleCatalog = collect(DefaultRole::cases())->map(function (DefaultRole $defaultRole) use (
            $allPermissions,
            $assignable,
            $descriptions,
            $roles
        ) {
            $role = $roles->get($defaultRole->value);
            $permissions = $defaultRole === DefaultRole::SuperAdmin
                ? $allPermissions
                : collect($role?->permissions ?? [])->pluck('name')->values();
            $scope = match ($defaultRole) {
                DefaultRole::SuperAdmin, DefaultRole::Admin => 'platform',
                DefaultRole::Customer => 'customer',
                default => 'restaurant',
            };

            return [
                'id' => $role?->id,
                'name' => $defaultRole->value,
                'display_name' => $role?->display_name ?: str($defaultRole->value)->headline()->toString(),
                'description' => $descriptions[$defaultRole->value],
                'scope' => $scope,
                'assignable' => $assignable->contains($defaultRole->value),
                'permission_mode' => $defaultRole === DefaultRole::SuperAdmin ? 'all' : 'explicit',
                'permission_count' => $permissions->count(),
                'permissions' => $permissions,
            ];
        })->values();

        $planFeatures = collect($tenant->activeSubscription?->plan?->features ?? []);
        $featureCatalog = collect(config('saas.default_features', []))
            ->merge(config('saas.enterprise_features', []))
            ->unique()
            ->map(fn (string $feature) => [
                'key' => $feature,
                'name' => __("saas::subscription_plans.features.{$feature}"),
                'enabled' => $planFeatures->isEmpty() || $planFeatures->contains($feature),
            ])
            ->values();

        return [
            'roles' => $roleCatalog,
            'permissions' => $allPermissions,
            'features' => $featureCatalog,
            'assignable_roles' => $assignable->values(),
        ];
    }

    private function tenant(int $id): Tenant
    {
        return Tenant::query()->withoutGlobalScopes()->withoutGlobalActive()->withTrashed()->findOrFail($id);
    }

    private function provisioningPayload(SaasProvisioningRun $run, ?ProvisioningOrchestratorService $orchestrator = null): array
    {
        $progress = $orchestrator?->progress($run) ?? [];

        return [
            'id' => $run->id,
            'uuid' => $run->uuid,
            'status' => $run->status,
            'state' => $run->state(),
            'progress' => $run->progress,
            'current_step' => $run->current_step,
            'estimated_remaining_seconds' => $progress['estimated_remaining_seconds'] ?? null,
            'current_job' => $progress['current_job'] ?? $run->current_step,
            'completed_jobs' => $progress['completed_jobs'] ?? [],
            'failed_jobs' => $progress['failed_jobs'] ?? [],
            'steps' => $run->steps ?? [],
            'error' => $run->error,
            'tenant' => $run->relationLoaded('tenant') && $run->tenant
                ? $run->tenant->only(['id', 'name', 'slug', 'domain'])
                : ['id' => $run->tenant_id],
            'branch' => $run->relationLoaded('branch') && $run->branch
                ? $run->branch->only(['id', 'name'])
                : ['id' => $run->branch_id],
            'started_at' => $run->started_at,
            'completed_at' => $run->completed_at,
            'failed_at' => $run->failed_at,
        ];
    }

    private function deliveryPayload(SaasDeliveryJob $job): array
    {
        return [
            'id' => $job->id,
            'uuid' => $job->uuid,
            'type' => $job->type,
            'status' => $job->status,
            'progress' => $job->progress,
            'result' => $job->result,
            'error' => $job->error,
            'tenant' => $job->relationLoaded('tenant') && $job->tenant
                ? $job->tenant->only(['id', 'name', 'slug', 'domain'])
                : ['id' => $job->tenant_id],
            'started_at' => $job->started_at,
            'completed_at' => $job->completed_at,
            'failed_at' => $job->failed_at,
        ];
    }

    private function billingSummary(): array
    {
        if (! Schema::hasTable('tenant_subscriptions')) {
            return [
                'active_subscriptions' => 0,
                'past_due' => 0,
                'cancelled' => 0,
                'estimated_mrr' => 0,
                'currency' => config('app.currency', 'INR'),
                'open_invoices' => 0,
                'overdue_invoices' => 0,
                'paid_invoices' => 0,
                'outstanding_amount' => 0,
                'today_collection' => 0,
                'failed_payments' => 0,
                'estimated_arr' => 0,
            ];
        }

        // A deleted restaurant keeps its subscription row, so counting every
        // subscription reported an archived account as an active one and
        // inflated MRR/ARR with it. Restrict to restaurants that still exist.
        $liveTenantIds = Tenant::query()
            ->withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->pluck('id');

        $subscriptions = TenantSubscription::query()
            ->withoutGlobalScopes()
            ->whereIn('tenant_id', $liveTenantIds)
            ->with('plan:id,price,currency')
            ->get()
            ->groupBy('tenant_id')
            ->map(fn ($rows) => $rows->sortByDesc('id')->first())
            ->values();
        $active = $subscriptions->whereIn('status', ['trial', 'active']);
        $invoiceSummary = [
            'open_invoices' => 0,
            'overdue_invoices' => 0,
            'paid_invoices' => 0,
            'outstanding_amount' => 0,
            'today_collection' => 0,
            'failed_payments' => 0,
        ];

        if (Schema::hasTable('saas_billing_invoices')) {
            $invoices = SaasBillingInvoice::query()->withoutGlobalScopes()->get();
            $invoiceSummary = [
                'open_invoices' => $invoices->whereIn('status', ['issued', 'payment_pending'])->count(),
                'overdue_invoices' => $invoices->where('status', 'overdue')->count(),
                'paid_invoices' => $invoices->where('status', 'paid')->count(),
                'outstanding_amount' => round((float) $invoices->whereIn('status', ['issued', 'payment_pending', 'overdue'])->sum('amount'), 2),
                'today_collection' => round((float) $invoices
                    ->where('status', 'paid')
                    ->filter(fn (SaasBillingInvoice $invoice) => $invoice->paid_at?->isToday())
                    ->sum('amount'), 2),
                'failed_payments' => $invoices->where('status', 'failed')->count(),
            ];
        }

        $estimatedMrr = round($active->sum(fn (TenantSubscription $subscription) => (float) ($subscription->plan?->price ?? 0)), 2);

        return [
            'active_subscriptions' => $active->count(),
            'past_due' => $subscriptions->where('status', 'past_due')->count(),
            'cancelled' => $subscriptions->whereIn('status', ['cancelled', 'expired'])->count(),
            'estimated_mrr' => $estimatedMrr,
            'estimated_arr' => round($estimatedMrr * 12, 2),
            'currency' => $active->first()?->plan?->currency ?? config('app.currency', 'INR'),
        ] + $invoiceSummary;
    }

    private function onboardingPendingCount(array $tenantIds): int
    {
        if (! Schema::hasTable('saas_provisioning_runs') || $tenantIds === []) {
            return 0;
        }

        // A restaurant may have several retries. Counting every failed run made
        // the dashboard drift upward forever; only the latest run is actionable.
        $latestRunIds = SaasProvisioningRun::query()
            ->withoutGlobalScopes()
            ->whereIn('tenant_id', $tenantIds)
            ->selectRaw('MAX(id)')
            ->groupBy('tenant_id');

        return SaasProvisioningRun::query()
            ->withoutGlobalScopes()
            ->whereIn('id', $latestRunIds)
            ->whereIn('status', ['pending', 'queued', 'running', 'partially_completed', 'failed'])
            ->count();
    }

    private function licenseSummary(): array
    {
        if (! Schema::hasTable('saas_coupons')) {
            return ['coupons' => 0, 'active_coupons' => 0, 'activation_keys' => 0, 'active_activation_keys' => 0, 'expiring_trials' => 0];
        }

        return [
            'coupons' => SaasCoupon::query()->withoutGlobalScopes()->count(),
            'active_coupons' => SaasCoupon::query()->withoutGlobalScopes()->where('is_active', true)->count(),
            'activation_keys' => SaasActivationKey::query()->withoutGlobalScopes()->count(),
            'active_activation_keys' => SaasActivationKey::query()->withoutGlobalScopes()->where('status', 'active')->count(),
            'expiring_trials' => TenantSubscription::query()
                ->withoutGlobalScopes()
                ->where('status', 'trial')
                ->whereBetween('trial_ends_at', [now(), now()->addDays(30)])
                ->count(),
        ];
    }

    private function tenantAnalytics(): array
    {
        $withCount = [];
        if (Schema::hasTable('branches')) {
            $withCount[] = 'branches';
        }
        if (Schema::hasTable('tenant_subscriptions')) {
            $withCount[] = 'subscriptions';
        }

        return Tenant::query()
            ->withoutGlobalScopes()
            ->withoutGlobalActive()
            ->withTrashed()
            ->when($withCount !== [], fn ($query) => $query->withCount($withCount))
            ->latest()
            ->limit(10)
            ->get()
            ->map(function (Tenant $tenant) {
                $branchIds = Schema::hasTable('branches')
                    ? Branch::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->pluck('id')
                    : collect();
                $orders = class_exists(Order::class) && Schema::hasTable('orders')
                    ? Order::query()->withoutGlobalScopes()->whereIn('branch_id', $branchIds)
                    : null;

                return [
                    'id' => $tenant->id,
                    'name' => $tenant->name,
                    'slug' => $tenant->slug,
                    'status' => $tenant->trashed() ? 'deleted' : ($tenant->is_active ? 'active' : 'suspended'),
                    'branches' => $tenant->branches_count ?? 0,
                    'subscriptions' => $tenant->subscriptions_count ?? 0,
                    'orders' => $orders ? (clone $orders)->count() : 0,
                    'revenue' => $this->tenantRevenue($branchIds),
                    'created_at' => dateTimeFormat($tenant->created_at),
                ];
            })
            ->all();
    }

    private function executiveAnalytics($tenants, $subscriptions): array
    {
        $activeSubscriptions = $subscriptions->whereIn('status', ['trial', 'active', 'grace']);
        $cancelledSubscriptions = $subscriptions->whereIn('status', ['cancelled', 'expired']);
        $closedSubscriptionCount = $activeSubscriptions->count() + $cancelledSubscriptions->count();
        $currentMonthGrowth = $tenants->where('created_at', '>=', now()->startOfMonth())->count();
        $previousMonthGrowth = $tenants->filter(fn (Tenant $tenant) => $tenant->created_at?->between(
            now()->subMonthNoOverflow()->startOfMonth(),
            now()->subMonthNoOverflow()->endOfMonth()
        ))->count();

        $invoices = Schema::hasTable('saas_billing_invoices')
            ? SaasBillingInvoice::query()->withoutGlobalScopes()->get()
            : collect();
        $invoiceTotal = (float) $invoices->sum('amount');
        $collectedTotal = (float) $invoices->where('status', 'paid')->sum('amount');

        $months = collect(range(5, 0))->map(fn (int $offset) => now()->subMonths($offset));
        $days = collect(range(6, 0))->map(fn (int $offset) => now()->subDays($offset));

        return [
            'collection_rate' => $invoiceTotal > 0 ? round(($collectedTotal / $invoiceTotal) * 100, 1) : 0,
            'customer_growth_rate' => $previousMonthGrowth > 0
                ? round((($currentMonthGrowth - $previousMonthGrowth) / $previousMonthGrowth) * 100, 1)
                : ($currentMonthGrowth > 0 ? 100 : 0),
            'churn_rate' => $closedSubscriptionCount > 0 ? round(($cancelledSubscriptions->count() / $closedSubscriptionCount) * 100, 1) : 0,
            'retention_rate' => $closedSubscriptionCount > 0 ? round(($activeSubscriptions->count() / $closedSubscriptionCount) * 100, 1) : 0,
            'trial_conversion' => $subscriptions->count() > 0 ? round(($subscriptions->where('status', 'active')->count() / $subscriptions->count()) * 100, 1) : 0,
            'expiring_plans' => $subscriptions->filter(fn (TenantSubscription $subscription) => $subscription->ends_at?->between(now(), now()->addDays(30)))->count(),
            'revenue_trend' => $days->map(fn ($date) => [
                'label' => $date->format('D'),
                'value' => round((float) $invoices->where('status', 'paid')->filter(fn (SaasBillingInvoice $invoice) => $invoice->paid_at?->isSameDay($date))->sum('amount'), 2),
            ])->values(),
            'restaurant_growth' => $months->map(fn ($date) => [
                'label' => $date->format('M'),
                'value' => $tenants->filter(fn (Tenant $tenant) => $tenant->created_at?->isSameMonth($date))->count(),
            ])->values(),
            'subscription_growth' => $months->map(fn ($date) => [
                'label' => $date->format('M'),
                'value' => $subscriptions->filter(fn (TenantSubscription $subscription) => $subscription->created_at?->isSameMonth($date))->count(),
            ])->values(),
            'payment_collection' => $months->map(fn ($date) => [
                'label' => $date->format('M'),
                'value' => round((float) $invoices->where('status', 'paid')->filter(fn (SaasBillingInvoice $invoice) => $invoice->paid_at?->isSameMonth($date))->sum('amount'), 2),
            ])->values(),
            'active_vs_inactive' => [
                ['label' => 'Active', 'value' => $tenants->where('is_active', true)->whereNull('deleted_at')->count()],
                ['label' => 'Inactive', 'value' => $tenants->where('is_active', false)->whereNull('deleted_at')->count()],
            ],
            'top_plans' => $activeSubscriptions->groupBy(fn (TenantSubscription $subscription) => $subscription->plan?->name ?? 'No plan')
                ->map(fn ($rows, string $name) => ['label' => $name, 'value' => $rows->count()])
                ->sortByDesc('value')->take(5)->values(),
            'top_cities' => $this->topCities(),
        ];
    }

    private function topCities(): array
    {
        if (! Schema::hasTable('branches') || ! Schema::hasColumn('branches', 'city')) {
            return [];
        }

        return DB::table('branches')
            ->whereNotNull('city')
            ->where('city', '!=', '')
            ->select('city as label', DB::raw('count(*) as value'))
            ->groupBy('city')
            ->orderByDesc('value')
            ->limit(5)
            ->get()->map(fn ($row) => ['label' => $row->label, 'value' => (int) $row->value])->all();
    }

    private function provisioningSummary(ProvisioningOrchestratorService $orchestrator): array
    {
        if (! Schema::hasTable('saas_provisioning_runs')) {
            return [
                'metrics' => [
                    'average_seconds' => null,
                    'fastest_seconds' => null,
                    'slowest_seconds' => null,
                    'failure_rate' => 0,
                    'retry_rate' => 0,
                    'step_durations' => [],
                ],
                'queue' => [],
            ];
        }

        return [
            'metrics' => $orchestrator->metrics(),
            'queue' => SaasProvisioningRun::query()
                ->with(['tenant:id,name,slug,domain'])
                ->latest()
                ->limit(10)
                ->get()
                ->map(fn (SaasProvisioningRun $run) => $this->provisioningPayload($run, $orchestrator))
                ->values(),
        ];
    }

    private function tenantRevenue($branchIds): float
    {
        if (class_exists(Payment::class) && Schema::hasTable('payments')) {
            return round((float) Payment::query()->withoutGlobalScopes()->whereIn('branch_id', $branchIds)->sum('amount'), 2);
        }

        if (Schema::hasTable('orders')) {
            return round((float) DB::table('orders')->whereIn('branch_id', $branchIds)->sum('total'), 2);
        }

        return 0;
    }

    private function alerts($healthRows, SaasRealtimeIncidentService $realtimeIncidents): array
    {
        $healthAlerts = $healthRows
            ->filter(fn (array $row) => $row['database'] !== 'ok' || $row['storage'] !== 'ok' || ($row['failed_jobs'] ?? 0) > 0)
            ->map(fn (array $row) => [
                'tenant_id' => $row['tenant_id'],
                'tenant' => $row['tenant'],
                'severity' => $row['database'] !== 'ok' || $row['storage'] !== 'ok' ? 'critical' : 'warning',
                'message' => $row['database'] !== 'ok'
                    ? 'Database health check failed.'
                    : (($row['storage'] !== 'ok') ? 'Tenant storage is missing.' : 'Failed queue jobs need review.'),
            ])
            ->values()
            ->all();

        $incidentAlerts = $realtimeIncidents->unresolvedByTenant(12)
            ->map(fn (array $incident) => [
                'tenant_id' => $incident['tenant_id'],
                'tenant' => $incident['tenant'],
                'severity' => $incident['severity'],
                'message' => "{$incident['incident_count']} unresolved {$incident['category']} incident(s) reported by restaurant agents.",
            ])
            ->all();

        return collect([...$healthAlerts, ...$incidentAlerts])
            ->unique(fn (array $alert) => "{$alert['tenant_id']}:{$alert['message']}")
            ->values()
            ->all();
    }

    private function alertChannels(): array
    {
        return collect(config('saas.alerts.channels', ['email', 'in_app']))
            ->map(fn (string $channel) => match ($channel) {
                'email' => NotificationChannel::Email,
                'whatsapp' => NotificationChannel::WhatsApp,
                'sms' => NotificationChannel::Sms,
                default => NotificationChannel::InApp,
            })
            ->unique(fn (NotificationChannel $channel) => $channel->value)
            ->values()
            ->all();
    }

    private function tenantMessageChannels(array $channels): array
    {
        return collect($channels)
            ->map(fn (string $channel) => match ($channel) {
                'email' => NotificationChannel::Email,
                'whatsapp' => NotificationChannel::WhatsApp,
                default => NotificationChannel::InApp,
            })
            ->unique(fn (NotificationChannel $channel) => $channel->value)
            ->values()
            ->all();
    }

    private function tenantMessageRecipient(Tenant $tenant, NotificationChannel $channel): string
    {
        return match ($channel) {
            NotificationChannel::Email => $tenant->contact_email
                ?: abort(422, 'Tenant contact email is required for email messages.'),
            NotificationChannel::WhatsApp => $tenant->contact_phone
                ?: abort(422, 'Tenant contact phone is required for WhatsApp messages.'),
            default => 'tenant:'.$tenant->id,
        };
    }

    private function dispatchTenantMessage(
        Tenant $tenant,
        array $payload,
        NotificationDispatcherService $notifications
    ): \Illuminate\Support\Collection {
        return collect($this->tenantMessageChannels($payload['channels'] ?? ['in_app']))
            ->flatMap(function (NotificationChannel $channel) use ($tenant, $payload, $notifications) {
                $messagePayload = [
                    'tenant_id' => $tenant->id,
                    'tenant' => $tenant->name,
                    'title' => $payload['title'],
                    'message' => $payload['message'],
                    'template' => $payload['template'] ?? 'custom',
                    'priority' => $payload['priority'] ?? 'normal',
                    'sent_by' => auth()->id(),
                ];

                if ($channel === NotificationChannel::InApp) {
                    $this->createTenantUserNotifications($tenant, $messagePayload);
                }

                return $notifications->dispatch(
                    'saas_tenant_message',
                    $this->tenantMessageRecipient($tenant, $channel),
                    $messagePayload,
                    [$channel]
                );
            });
    }

    private function createTenantUserNotifications(Tenant $tenant, array $payload): void
    {
        $severity = match ($payload['priority'] ?? 'normal') {
            'critical' => 'error',
            'warning' => 'warning',
            'low' => 'info',
            default => 'info',
        };

        $notificationService = app(NotificationServiceInterface::class);

        User::query()
            ->withoutGlobalScopes()
            ->withoutGlobalActive()
            ->where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->select(['id'])
            ->chunkById(100, function ($users) use ($notificationService, $payload, $severity) {
                foreach ($users as $user) {
                    $notificationService->create([
                        'title' => $payload['title'],
                        'message' => $payload['message'],
                        'type' => 'communication',
                        'severity' => $severity,
                        'icon' => 'tabler-message',
                        'action_url' => '/admin/notifications',
                        'payload' => [
                            'tenant_id' => $payload['tenant_id'],
                            'template' => $payload['template'],
                            'sent_by' => $payload['sent_by'],
                            'source' => 'saas_communication_center',
                        ],
                    ], $user);
                }
            });
    }

    private function normalizeTenantDomain(string $domain): string
    {
        $domain = trim(strtolower($domain));
        $domain = preg_replace('#^https?://#', '', $domain) ?? $domain;
        $domain = preg_replace('#/.*$#', '', $domain) ?? $domain;
        return trim($domain, ". \t\n\r\0\x0B");
    }

    private function resolveTenantDomain(string $domain): array
    {
        if ($domain === '') {
            return [];
        }

        $records = @dns_get_record($domain, DNS_A + DNS_AAAA);
        if (is_array($records) && $records !== []) {
            return collect($records)
                ->map(fn (array $record) => $record['ip'] ?? $record['ipv6'] ?? null)
                ->filter()
                ->unique()
                ->values()
                ->all();
        }

        $legacyRecords = @gethostbynamel($domain);
        return is_array($legacyRecords)
            ? array_values(array_unique($legacyRecords))
            : [];
    }
}
