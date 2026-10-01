<?php

namespace Modules\WhatsAppCenter\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Branch\Models\Branch;
use Modules\Core\Http\Controllers\Controller;
use Modules\Support\ApiResponse;
use Modules\WhatsAppCenter\Services\WhatsAppCenterService;

class WhatsAppCenterController extends Controller
{
    public function __construct(protected WhatsAppCenterService $service) {}

    public function dashboard(Request $request): JsonResponse
    {
        $branchRule = Rule::exists('branches', 'id');
        if ($request->user()?->assignedToTenant() && ! $request->user()?->isSuperAdmin()) {
            $branchRule->where(fn ($query) => $query->where('tenant_id', $request->user()->tenantId()));
        }
        $data = $request->validate(['branch_id' => [
            'sometimes', 'nullable', 'integer',
            $branchRule,
        ]]);
        $branchId = $this->resolvedBranchId($request, $data['branch_id'] ?? null);

        return ApiResponse::success($this->service->getDashboard($branchId));
    }

    private function resolvedBranchId(Request $request, ?int $branchId = null): ?int
    {
        if (auth()->user()->assignedToBranch()) {
            return auth()->user()->branch_id;
        }

        $resolved = $branchId ?? ($request->integer('branch_id') ?: null);
        if ($resolved && $request->user()?->assignedToTenant() && ! $request->user()?->isSuperAdmin()) {
            abort_unless(Branch::query()->whereKey($resolved)->exists(), 404);
        }

        return $resolved;
    }
}
