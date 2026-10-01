<?php

namespace Modules\Saas\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Core\Http\Controllers\Controller;
use Modules\Pos\Models\PosTerminalDevice;
use Modules\Saas\Models\CustomerAppBuild;
use Modules\Saas\Services\CustomerApp\CustomerAppBuildService;
use Modules\Saas\Services\Entitlements\EffectiveTenantEntitlementService;
use Modules\Saas\Services\Workspace\TenantWorkspaceService;
use Modules\Saas\Services\Workspace\WaiterActivationChallengeService;
use Modules\Saas\Services\Provisioning\TenantClientConfigService;
use Modules\Saas\Models\Tenant;
use Modules\Saas\Traits\ResolvesCurrentTenant;
use Modules\Support\ApiResponse;

class TenantAppsController extends Controller
{
    use ResolvesCurrentTenant;

    public function __construct(
        private readonly EffectiveTenantEntitlementService $entitlements,
        private readonly TenantWorkspaceService $workspace,
        private readonly CustomerAppBuildService $builds,
        private readonly WaiterActivationChallengeService $activationChallenges,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tenant = $this->currentTenant($request);
        $features = $this->entitlements->features($tenant);
        $featureMap = ['customer_app' => 'customer_app', 'waiter_app' => 'waiter_app', 'windows_agent' => 'printer', 'printer_utility' => 'printer', 'kitchen_app' => 'kitchen', 'customer_display' => 'customer_display'];
        $artifacts = collect($this->workspace->resources($tenant)['artifacts'] ?? [])->keyBy('key');
        $apps = collect($featureMap)->filter(fn ($feature) => in_array($feature, $features, true))->map(function ($feature, $key) use ($artifacts) {
            $artifact = $artifacts->get($key);
            return ['key' => $key, 'feature' => $feature, 'artifact' => $artifact, 'available' => (bool) data_get($artifact, 'is_downloadable', false)];
        })->values();

        $branchIds = DB::table('branches')->where('tenant_id', $tenant->id)->pluck('id');
        $devices = $request->user()->can('admin.settings.edit')
            ? PosTerminalDevice::query()->withoutGlobalScopes()->with(['branch:id,name', 'createdBy:id,name'])
            ->whereIn('branch_id', $branchIds)->latest('last_seen_at')->limit(100)->get()
            ->map(fn (PosTerminalDevice $device) => [
                'id' => $device->id, 'name' => $device->name ?: $device->device_id,
                'platform' => $device->platform, 'app_version' => $device->app_version,
                'branch' => $device->branch?->name, 'activated_by' => $device->createdBy?->name,
                'activated_at' => $device->created_at?->toIso8601String(), 'last_seen_at' => $device->last_seen_at?->toIso8601String(),
                'status' => $device->is_disabled ? 'revoked' : ($device->last_seen_at?->gte(now()->subSeconds((int) config('pos.fleet.offline_after_seconds', 90))) ? 'online' : 'offline'),
            ]) : collect();

        return ApiResponse::success(['features' => $features, 'apps' => $apps, 'waiter_devices' => $devices]);
    }

    public function revoke(Request $request, int $device): JsonResponse
    {
        $tenant = $this->currentTenant($request);
        abort_unless($this->entitlements->has($tenant, 'waiter_app'), 403, 'Waiter App is not included in this subscription.');
        $branchIds = DB::table('branches')->where('tenant_id', $tenant->id)->pluck('id');
        $terminal = PosTerminalDevice::query()->withoutGlobalScopes()->whereIn('branch_id', $branchIds)->whereKey($device)->firstOrFail();
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']]);
        $terminal->forceFill(['is_disabled' => true, 'disabled_at' => now(), 'disabled_by' => $request->user()->id, 'disabled_reason' => $data['reason']])->save();
        activity('waiter_device')->performedOn($terminal)->causedBy($request->user())->withProperties(['tenant_id' => $tenant->id])->log('Waiter device revoked');
        return ApiResponse::success(['id' => $terminal->id, 'status' => 'revoked'], 'Device access revoked.');
    }

    public function versionOptions(Request $request): JsonResponse
    {
        $tenant = $this->currentTenant($request);
        abort_unless($this->entitlements->has($tenant, 'customer_app_build'), 403, 'Customer App builds are not included in this subscription.');
        return ApiResponse::success(['versions' => $this->builds->versionOptions($tenant)]);
    }

    public function createWaiterActivation(Request $request): JsonResponse
    {
        $tenant = $this->currentTenant($request);
        abort_unless($this->entitlements->has($tenant, 'waiter_app'), 403, 'Waiter App is not included in this subscription.');

        return ApiResponse::success($this->activationChallenges->issue($tenant, $request->user()->id));
    }

    /**
     * Return waiter runtime configuration to an authorized SaaS operator.
     * This is deliberately separate from the public, non-disclosing slug
     * endpoint and from activation issuance.
     */
    public function saasWaiterConfig(Tenant $tenant, TenantClientConfigService $config): JsonResponse
    {
        abort_unless($tenant->is_active, 404);
        abort_unless($this->entitlements->has($tenant, 'waiter_app'), 403, 'Waiter App is not included in this subscription.');

        return ApiResponse::success($config->config($tenant));
    }

    /** Issue a short-lived, single-use activation only on an explicit action. */
    public function createSaasWaiterActivation(Request $request, Tenant $tenant): JsonResponse
    {
        abort_unless($tenant->is_active, 404);
        abort_unless($this->entitlements->has($tenant, 'waiter_app'), 403, 'Waiter App is not included in this subscription.');

        $payload = $this->activationChallenges->issue($tenant, $request->user()->id);
        activity('waiter_activation')
            ->performedOn($tenant)
            ->causedBy($request->user())
            ->withProperties(['tenant_id' => $tenant->id, 'branch_id' => $payload['branch_id']])
            ->log('Waiter activation issued by SaaS operator');

        return ApiResponse::success($payload);
    }
}
