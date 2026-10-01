<?php

namespace Modules\Saas\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\Controller;
use Modules\Saas\Models\Tenant;
use Modules\Saas\Services\ControlPlane\SaasControlPlaneService;
use Modules\Support\ApiResponse;

class SaasControlPlaneController extends Controller
{
    public function overview(Request $request, SaasControlPlaneService $service): JsonResponse
    {
        $request->validate(['tenant_id' => ['nullable', 'integer', 'exists:tenants,id']]);

        return ApiResponse::success($service->overview($request->integer('tenant_id') ?: null));
    }

    public function features(int $tenant, SaasControlPlaneService $service): JsonResponse
    {
        return ApiResponse::success([
            'features' => $service->featureMatrix($this->tenant($tenant)),
        ]);
    }

    public function updateFeature(Request $request, int $tenant, SaasControlPlaneService $service): JsonResponse
    {
        $validated = $request->validate([
            'feature' => ['required', 'string', 'max:100'],
            'enabled' => ['required', 'boolean'],
            'branch_id' => ['nullable', 'integer'],
        ]);

        return ApiResponse::updated([
            'features' => $service->updateFeatureFlag(
                $this->tenant($tenant),
                $validated['feature'],
                (bool) $validated['enabled'],
                $validated['branch_id'] ?? null
            ),
        ], 'Feature flag updated.');
    }

    public function usage(int $tenant, SaasControlPlaneService $service): JsonResponse
    {
        return ApiResponse::success([
            'usage' => $service->usageSummary($this->tenant($tenant)->loadMissing('featureLimits')),
        ]);
    }

    public function activity(int $tenant, SaasControlPlaneService $service): JsonResponse
    {
        return ApiResponse::success([
            'activity' => $service->activityFeed($this->tenant($tenant)),
        ]);
    }

    private function tenant(int $id): Tenant
    {
        return Tenant::query()
            ->withoutGlobalScopes()
            ->withoutGlobalActive()
            ->with(['activeSubscription.plan', 'featureLimits'])
            ->findOrFail($id);
    }
}
