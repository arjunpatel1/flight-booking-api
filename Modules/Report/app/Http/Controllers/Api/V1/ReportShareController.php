<?php

namespace Modules\Report\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Branch\Models\Branch;
use Modules\Core\Http\Controllers\Controller;
use Modules\Report\Jobs\SendReportEmailJob;
use Modules\Support\ApiResponse;

class ReportShareController extends Controller
{
    public function email(Request $request): JsonResponse
    {
        $branchRule = Rule::exists('branches', 'id');
        if ($request->user()?->assignedToTenant() && ! $request->user()?->isSuperAdmin()) {
            $branchRule->where(fn ($query) => $query->where('tenant_id', $request->user()->tenantId()));
        }

        $data = $request->validate([
            'report_type' => ['required', Rule::in(['sales_summary', 'financial_summary'])],
            'recipient' => ['required', 'email:rfc', 'max:254'],
            'date' => ['required', 'date'],
            'branch_id' => ['sometimes', 'nullable', 'integer', $branchRule],
        ]);

        if ($request->user()?->assignedToBranch()) {
            $data['branch_id'] = $request->user()->branchId();
        }
        $tenantId = $request->user()?->tenantId()
            ?: Branch::query()->withoutGlobalScopes()->whereKey($data['branch_id'] ?? null)->value('tenant_id');
        abort_unless($tenantId, 422, 'A tenant-bound restaurant context is required.');

        SendReportEmailJob::dispatch(
            (int) $tenantId,
            isset($data['branch_id']) ? (int) $data['branch_id'] : null,
            strtolower($data['recipient']),
            $data['report_type'],
            $data['date'],
        );

        activity('report_share')
            ->causedBy($request->user())
            ->withProperties([
                'tenant_id' => (int) $tenantId,
                'branch_id' => $data['branch_id'] ?? null,
                'report_type' => $data['report_type'],
                'channel' => 'email',
                'recipient_fingerprint' => hash('sha256', strtolower($data['recipient'])),
            ])
            ->log('Report shared through email');

        return ApiResponse::success(['queued' => true, 'channel' => 'email']);
    }
}
