<?php

namespace Modules\Saas\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\Controller;
use Modules\Saas\Services\Workspace\ActivationKeyService;
use Modules\Saas\Services\Workspace\TenantWorkspaceService;
use Modules\Saas\Traits\ResolvesCurrentTenant;
use Modules\Support\ApiResponse;

/**
 * Tenant self-service. Every endpoint answers about the caller's own
 * restaurant and takes no tenant identifier, so none of this can be pointed at
 * another tenant. Read-only apart from activation verification, which also
 * writes nothing.
 *
 * These are intentionally NOT behind an `admin.saas.*` permission: a restaurant
 * owner must be able to onboard without platform access.
 */
class TenantWorkspaceController extends Controller
{
    use ResolvesCurrentTenant;

    public function __construct(
        private readonly TenantWorkspaceService $workspace,
        private readonly ActivationKeyService $activationKeys,
    ) {
    }

    /**
     * Dashboard payload: status, subscription, today's trading, devices,
     * printers, setup progress, support and announcements.
     */
    public function overview(Request $request): JsonResponse
    {
        return ApiResponse::success($this->workspace->overview($this->currentTenant($request)));
    }

    /**
     * Download Center, Documentation Center, support contacts and this
     * restaurant's activation key.
     */
    public function resources(Request $request): JsonResponse
    {
        return ApiResponse::success($this->workspace->resources($this->currentTenant($request)));
    }

    /**
     * Confirms a typed activation key belongs to this restaurant.
     *
     * The client validates format and checksum locally first (see
     * `src/utils/activationKey.ts`); this is the authoritative check. It is
     * throttled at the route because it accepts user-supplied secrets.
     */
    public function verifyActivationKey(Request $request): JsonResponse
    {
        $data = $request->validate([
            'key' => ['required', 'string', 'max:64'],
        ]);

        $tenant = $this->currentTenant($request);

        if (! $this->activationKeys->isWellFormed($data['key'])) {
            return ApiResponse::errors(
                errors: ['key' => [__('saas::workspace.activation.malformed')]],
                message: __('saas::workspace.activation.malformed'),
                code: 422,
            );
        }

        if (! $this->activationKeys->matches($tenant, $data['key'])) {
            return ApiResponse::errors(
                errors: ['key' => [__('saas::workspace.activation.mismatch')]],
                message: __('saas::workspace.activation.mismatch'),
                code: 422,
            );
        }

        return ApiResponse::success([
            'verified' => true,
            'restaurant' => $tenant->name,
            'slug' => $tenant->slug,
            'verified_at' => now()->toIso8601String(),
        ], __('saas::workspace.activation.verified'));
    }
}
