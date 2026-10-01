<?php

namespace Modules\WhatsAppCenter\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Branch\Models\Branch;
use Modules\Core\Http\Controllers\Controller;
use Modules\Support\ApiResponse;
use Modules\WhatsAppCenter\Services\WhatsAppReportShareService;

class WhatsAppReportShareController extends Controller
{
    public function __construct(protected WhatsAppReportShareService $service) {}

    public function reportTypes(): JsonResponse
    {
        return ApiResponse::success(['report_types' => $this->service->getReportTypes()]);
    }

    public function share(Request $request): JsonResponse
    {
        $request->merge([
            'recipients' => collect($request->input('recipients', []))
                ->map(fn ($phone) => preg_replace('/[\s\-().]/', '', (string) $phone))
                ->values()
                ->all(),
        ]);
        $branchRule = Rule::exists('branches', 'id');
        if ($request->user()?->assignedToTenant() && ! $request->user()?->isSuperAdmin()) {
            $branchRule->where(fn ($query) => $query->where('tenant_id', $request->user()->tenantId()));
        }
        $data = $request->validate([
            'report_type' => ['required', Rule::in(array_keys(WhatsAppReportShareService::SHAREABLE_REPORTS))],
            'recipients'  => ['required', 'array', 'min:1', 'max:20'],
            'recipients.*' => ['required', 'string', 'regex:/^\+[1-9][0-9]{7,14}$/'],
            'template'    => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9_]+$/'],
            'date'        => ['sometimes', 'date'],
            'branch_id'   => [
                'sometimes', 'nullable', 'integer',
                $branchRule,
            ],
        ]);

        if ($request->user()?->assignedToBranch()) {
            $data['branch_id'] = $request->user()->branchId();
        } elseif (! empty($data['branch_id']) && $request->user()?->assignedToTenant() && ! $request->user()?->isSuperAdmin()) {
            abort_unless(Branch::query()->whereKey($data['branch_id'])->exists(), 404);
        }

        $data['tenant_id'] = $request->user()?->tenantId()
            ?: Branch::query()->withoutGlobalScopes()->whereKey($data['branch_id'] ?? null)->value('tenant_id');
        abort_unless($data['tenant_id'], 422, 'A tenant-bound restaurant context is required.');

        $result = $this->service->share($data);
        activity('report_share')
            ->causedBy($request->user())
            ->withProperties([
                'tenant_id' => $data['tenant_id'],
                'branch_id' => $data['branch_id'] ?? null,
                'report_type' => $data['report_type'],
                'recipient_count' => count($data['recipients']),
                'recipient_fingerprints' => collect($data['recipients'])->map(fn ($phone) => hash('sha256', $phone))->all(),
            ])
            ->log('Report shared through WhatsApp');

        return ApiResponse::success($result);
    }
}
