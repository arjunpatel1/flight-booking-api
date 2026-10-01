<?php

namespace Modules\WhatsAppCenter\Http\Controllers\Api\V1;

use App\NexDine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Branch\Models\Branch;
use Modules\Core\Http\Controllers\Controller;
use Modules\Support\ApiResponse;
use Modules\WhatsAppCenter\Jobs\ScheduledReportWhatsAppJob;
use Modules\WhatsAppCenter\Models\WhatsAppSchedule;
use Modules\WhatsAppCenter\Services\WhatsAppReportShareService;

class WhatsAppScheduleController extends Controller
{
    public function index(): JsonResponse
    {
        return ApiResponse::pagination(
            WhatsAppSchedule::with(['branch', 'user'])->latest()->paginate(NexDine::paginate())
        );
    }

    public function store(Request $request): JsonResponse
    {
        $this->normaliseRecipients($request);
        $branchRule = Rule::exists('branches', 'id');
        if ($request->user()?->assignedToTenant() && ! $request->user()?->isSuperAdmin()) {
            $branchRule->where(fn ($query) => $query->where('tenant_id', $request->user()->tenantId()));
        }
        $data = $request->validate([
            'name'          => ['required', 'string', 'max:200'],
            'report_type'   => ['required', Rule::in(array_keys(WhatsAppReportShareService::SHAREABLE_REPORTS))],
            'template_name' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9_]+$/'],
            'frequency'     => ['required', Rule::in(['daily', 'weekly', 'monthly'])],
            'run_at'        => ['required', 'date_format:H:i'],
            'day_of_week'   => ['sometimes', 'integer', 'between:0,6'],
            'day_of_month'  => ['sometimes', 'integer', 'between:1,28'],
            'recipients'    => ['required', 'array', 'min:1', 'max:20'],
            'recipients.*'  => ['required', 'string', 'regex:/^\+[1-9][0-9]{7,14}$/'],
            'branch_id'     => [
                'sometimes', 'nullable', 'integer',
                $branchRule,
            ],
            'is_active'     => ['sometimes', 'boolean'],
        ]);

        $data['branch_id'] = $this->resolvedBranchId($request, $data['branch_id'] ?? null);
        abort_unless($data['branch_id'], 422, 'A restaurant branch is required for scheduled reports.');
        $data['user_id'] = auth()->id();
        $schedule = WhatsAppSchedule::create($data);
        $schedule->update(['next_run_at' => $schedule->computeNextRunAt()]);

        return ApiResponse::success($schedule->load(['branch', 'user']), 201);
    }

    public function update(Request $request, WhatsAppSchedule $schedule): JsonResponse
    {
        $this->normaliseRecipients($request);
        $data = $request->validate([
            'name'          => ['sometimes', 'string', 'max:200'],
            'report_type'   => ['sometimes', Rule::in(array_keys(WhatsAppReportShareService::SHAREABLE_REPORTS))],
            'template_name' => ['sometimes', 'string', 'max:100', 'regex:/^[A-Za-z0-9_]+$/'],
            'frequency'     => ['sometimes', Rule::in(['daily', 'weekly', 'monthly'])],
            'run_at'        => ['sometimes', 'date_format:H:i'],
            'day_of_week'   => ['sometimes', 'nullable', 'integer', 'between:0,6'],
            'day_of_month'  => ['sometimes', 'nullable', 'integer', 'between:1,28'],
            'recipients'    => ['sometimes', 'array', 'min:1', 'max:20'],
            'recipients.*'  => ['string', 'regex:/^\+[1-9][0-9]{7,14}$/'],
            'is_active'     => ['sometimes', 'boolean'],
        ]);

        $schedule->update($data);
        $schedule->update(['next_run_at' => $schedule->fresh()->computeNextRunAt()]);

        return ApiResponse::success($schedule->load(['branch', 'user']));
    }

    public function destroy(WhatsAppSchedule $schedule): JsonResponse
    {
        $schedule->delete();

        return ApiResponse::success(['deleted' => true]);
    }

    public function toggle(WhatsAppSchedule $schedule): JsonResponse
    {
        $schedule->update(['is_active' => !$schedule->is_active]);

        return ApiResponse::success(['is_active' => $schedule->fresh()->is_active]);
    }

    public function runNow(WhatsAppSchedule $schedule): JsonResponse
    {
        $tenantId = Branch::query()->withoutGlobalScopes()->whereKey($schedule->branch_id)->value('tenant_id');
        abort_unless($tenantId, 422, 'The scheduled report must belong to a tenant branch.');
        ScheduledReportWhatsAppJob::dispatch($schedule->id, (int) $tenantId, (int) $schedule->branch_id);

        return ApiResponse::success(['queued' => true]);
    }

    private function resolvedBranchId(Request $request, ?int $branchId): ?int
    {
        if ($request->user()?->assignedToBranch()) {
            return $request->user()->branchId();
        }

        if ($branchId && $request->user()?->assignedToTenant() && ! $request->user()?->isSuperAdmin()) {
            abort_unless(Branch::query()->whereKey($branchId)->exists(), 404);
        }

        return $branchId ?: $request->user()?->effective_branch?->id;
    }

    private function normaliseRecipients(Request $request): void
    {
        if (! $request->has('recipients')) {
            return;
        }

        $request->merge([
            'recipients' => collect($request->input('recipients', []))
                ->map(fn ($phone) => preg_replace('/[\s\-().]/', '', (string) $phone))
                ->values()
                ->all(),
        ]);
    }
}
