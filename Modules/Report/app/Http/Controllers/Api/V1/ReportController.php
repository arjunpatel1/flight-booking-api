<?php

namespace Modules\Report\Http\Controllers\Api\V1;

use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Branch\Models\Branch;
use Modules\Core\Http\Controllers\Controller;
use Modules\Report\Enums\ExportMethod;
use Modules\Report\Models\BranchDailyBusinessSummary;
use Modules\Report\Models\ReportExport;
use Modules\Report\Models\ReportJob;
use Modules\Report\Models\ReportSchedule;
use Modules\Report\Models\SavedReportFilter;
use Modules\Report\ReportManager;
use Modules\Report\Services\EnterpriseReportSummary\EnterpriseReportSummaryServiceInterface;
use Modules\Report\Services\Report\ReportServiceInterface;
use Modules\Support\ApiResponse;
use Modules\Support\Money;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    /**
     * Create a new instance of ReportController
     *
     * @param ReportServiceInterface $service
     */
    public function __construct(protected ReportServiceInterface $service)
    {
    }

    /**
     * Display a listing of the resource.
     *
     * @param Request $request
     * @param string $report
     * @return JsonResponse
     */
    public function index(Request $request, string $report): JsonResponse
    {
        return ApiResponse::success($this->service->renderReport($request, $report));
    }

    /**
     * Export report
     *
     * @param Request $request
     * @param string $report
     * @param ExportMethod $method
     * @return StreamedResponse|BinaryFileResponse
     */
    public function export(Request $request, string $report, ExportMethod $method): StreamedResponse|BinaryFileResponse
    {
        return $this->service->export($request, $report, $method);
    }

    public function ownerSummary(Request $request): JsonResponse
    {
        $data = $request->validate([
            'date' => ['sometimes', 'date'],
            'branch_id' => ['sometimes', 'nullable', 'integer', 'exists:branches,id'],
        ]);

        $businessDate = isset($data['date'])
            ? Carbon::parse($data['date'])->toDateString()
            : now()->toDateString();
        $branchId = $this->resolvedBranchId($request, $data['branch_id'] ?? null);

        $summaryQuery = fn() => BranchDailyBusinessSummary::query()
            ->whereDate('business_date', $businessDate)
            ->when($branchId, fn($query) => $query->where('branch_id', $branchId))
            ->selectRaw('COUNT(*) as branch_count')
            ->selectRaw('COALESCE(SUM(total_orders), 0) as total_orders')
            ->selectRaw('COALESCE(SUM(completed_paid_orders), 0) as completed_paid_orders')
            ->selectRaw('COALESCE(SUM(pending_orders), 0) as pending_orders')
            ->selectRaw('COALESCE(SUM(cancelled_orders), 0) as cancelled_orders')
            ->selectRaw('COALESCE(SUM(refunded_orders), 0) as refunded_orders')
            ->selectRaw('COALESCE(SUM(net_sales), 0) as net_sales')
            ->selectRaw('COALESCE(SUM(payment_ledger_total), 0) as payment_ledger_total')
            ->selectRaw('COALESCE(SUM(reconciliation_difference), 0) as reconciliation_difference')
            ->selectRaw('COALESCE(SUM(profit_total), 0) as profit_total')
            ->selectRaw('COALESCE(SUM(expense_total), 0) as expense_total')
            ->selectRaw('COALESCE(SUM(cash_in_hand), 0) as cash_in_hand')
            ->selectRaw('COALESCE(SUM(pending_collections), 0) as pending_collections')
            ->selectRaw('COALESCE(SUM(refund_total), 0) as refund_total')
            ->selectRaw('MAX(currency) as currency')
            ->selectRaw('MAX(calculated_at) as calculated_at')
            ->first();

        $summary = $summaryQuery();

        if ((int) ($summary->branch_count ?? 0) === 0) {
            app(EnterpriseReportSummaryServiceInterface::class)->rebuildDaily($businessDate, $branchId);
            $summary = $summaryQuery();
        }

        $currency = $summary->currency ?: setting('default_currency') ?: config('app.currency', 'INR');
        $trendStartDate = Carbon::parse($businessDate)->subDays(6)->toDateString();
        $trends = BranchDailyBusinessSummary::query()
            ->whereBetween('business_date', [$trendStartDate, $businessDate])
            ->when($branchId, fn($query) => $query->where('branch_id', $branchId))
            ->selectRaw('business_date')
            ->selectRaw('COALESCE(SUM(total_orders), 0) as total_orders')
            ->selectRaw('COALESCE(SUM(net_sales), 0) as net_sales')
            ->selectRaw('COALESCE(SUM(profit_total), 0) as profit_total')
            ->selectRaw('COALESCE(SUM(expense_total), 0) as expense_total')
            ->groupBy('business_date')
            ->orderBy('business_date')
            ->get()
            ->map(fn(BranchDailyBusinessSummary $row) => [
                'date' => $row->business_date->toDateString(),
                'orders' => (int) $row->total_orders,
                'net_sales' => (float) $row->net_sales,
                'profit_total' => (float) $row->profit_total,
                'expense_total' => (float) $row->expense_total,
            ])
            ->values();

        return ApiResponse::success([
            'schema_version' => 2,
            'calculation_basis' => 'completed_and_paid_orders',
            'date' => $businessDate,
            'branch_id' => $branchId,
            'branch_count' => (int) ($summary->branch_count ?? 0),
            'calculated_at' => $summary->calculated_at,
            'currency' => $currency,
            'timezone' => config('app.timezone'),
            'filters' => ['business_date' => $businessDate, 'branch_id' => $branchId],
            'reconciliation' => [
                'recognized_sales' => (float) ($summary->net_sales ?? 0),
                'payment_ledger_total' => (float) ($summary->payment_ledger_total ?? 0),
                'difference' => (float) ($summary->reconciliation_difference ?? 0),
                'balanced' => abs((float) ($summary->reconciliation_difference ?? 0)) < 0.01,
            ],
            'trends' => $trends,
            'cards' => [
                $this->ownerSummaryCard('today_sales', 'Today\'s Sales', 'tabler-report-money', $this->money($summary->net_sales, $currency), 'primary'),
                $this->ownerSummaryCard('today_orders', 'Completed & Paid Orders', 'tabler-shopping-cart', (int) ($summary->completed_paid_orders ?? 0), 'info'),
                $this->ownerSummaryCard('today_profit', 'Today\'s Profit', 'tabler-trending-up', $this->money($summary->profit_total, $currency), 'success'),
                $this->ownerSummaryCard('today_expenses', 'Today\'s Expenses', 'tabler-receipt-tax', $this->money($summary->expense_total, $currency), 'warning'),
                $this->ownerSummaryCard('cash_in_hand', 'Cash In Hand', 'tabler-cash-register', $this->money($summary->cash_in_hand, $currency), 'success'),
                $this->ownerSummaryCard('pending_collections', 'Pending Collections', 'tabler-hourglass', $this->money($summary->pending_collections, $currency), 'warning'),
                $this->ownerSummaryCard('pending_orders', 'Pending Orders', 'tabler-clock-pause', (int) ($summary->pending_orders ?? 0), 'warning'),
                $this->ownerSummaryCard('cancelled_orders', 'Cancelled Orders', 'tabler-shopping-cart-x', (int) ($summary->cancelled_orders ?? 0), 'error'),
                $this->ownerSummaryCard('refund_amount', 'Refund Amount', 'tabler-cash-banknote-off', $this->money($summary->refund_total, $currency), 'error'),
            ],
        ]);
    }

    public function exportCenter(Request $request): JsonResponse
    {
        $data = $request->validate([
            'limit' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'branch_id' => ['sometimes', 'nullable', 'integer', 'exists:branches,id'],
        ]);

        $branchId = $this->resolvedBranchId($request, $data['branch_id'] ?? null);
        $limit = $data['limit'] ?? 20;

        $exports = ReportExport::query()
            ->with(['reportJob:id,status,progress,queued_at,started_at,completed_at'])
            ->select([
                'id',
                'report_job_id',
                'branch_id',
                'requested_by',
                'report_key',
                'format',
                'status',
                'file_name',
                'file_size',
                'error_message',
                'expires_at',
                'completed_at',
                'created_at',
            ])
            ->where('requested_by', auth()->id())
            ->when(
                is_null($branchId),
                fn($query) => $query->whereNull('branch_id'),
                fn($query) => $query->where('branch_id', $branchId),
            )
            ->latest()
            ->limit($limit)
            ->get()
            ->map(fn(ReportExport $export) => $this->reportExportResource($export));

        $jobs = ReportJob::query()
            ->select([
                'id',
                'branch_id',
                'requested_by',
                'report_key',
                'status',
                'progress',
                'error_message',
                'queued_at',
                'started_at',
                'completed_at',
                'created_at',
            ])
            ->where('requested_by', auth()->id())
            ->when(
                is_null($branchId),
                fn($query) => $query->whereNull('branch_id'),
                fn($query) => $query->where('branch_id', $branchId),
            )
            ->latest()
            ->limit($limit)
            ->get()
            ->map(fn(ReportJob $job) => $this->reportJobResource($job));

        return ApiResponse::success([
            'exports' => $exports,
            'jobs' => $jobs,
        ]);
    }

    public function schedules(Request $request): JsonResponse
    {
        $data = $request->validate([
            'branch_id' => ['sometimes', 'nullable', 'integer', 'exists:branches,id'],
        ]);

        $branchId = $this->resolvedBranchId($request, $data['branch_id'] ?? null);

        $schedules = ReportSchedule::query()
            ->where('user_id', auth()->id())
            ->when(
                is_null($branchId),
                fn($query) => $query->whereNull('branch_id'),
                fn($query) => $query->where('branch_id', $branchId),
            )
            ->latest()
            ->get()
            ->map(fn(ReportSchedule $schedule) => $this->reportScheduleResource($schedule));

        return ApiResponse::success([
            'data' => $schedules,
        ]);
    }

    public function storeSchedule(Request $request): JsonResponse
    {
        $data = $request->validate([
            'report_key' => ['required', 'string', 'max:120'],
            'name' => ['required', 'string', 'max:120'],
            'frequency' => ['required', Rule::in(['daily', 'weekly', 'monthly'])],
            'run_at' => ['required', 'date_format:H:i'],
            'day_of_week' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:6'],
            'day_of_month' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:31'],
            'formats' => ['sometimes', 'array'],
            'formats.*' => ['string', 'max:20'],
            'recipients' => ['sometimes', 'array'],
            'recipients.*' => ['string', 'max:255'],
            'filters' => ['sometimes', 'array'],
            'is_active' => ['sometimes', 'boolean'],
            'branch_id' => ['sometimes', 'nullable', 'integer', 'exists:branches,id'],
        ]);

        $this->authorizeReport($data['report_key']);

        $branchId = $this->resolvedBranchId($request, $data['branch_id'] ?? null);
        $schedule = ReportSchedule::query()->create([
            'branch_id' => $branchId,
            'user_id' => auth()->id(),
            'report_key' => $data['report_key'],
            'name' => $data['name'],
            'frequency' => $data['frequency'],
            'run_at' => $data['run_at'],
            'day_of_week' => $data['day_of_week'] ?? null,
            'day_of_month' => $data['day_of_month'] ?? null,
            'formats' => array_values($data['formats'] ?? ['pdf']),
            'recipients' => array_values($data['recipients'] ?? []),
            'filters' => $data['filters'] ?? [],
            'is_active' => $data['is_active'] ?? true,
            'next_run_at' => $this->nextScheduleRunAt($data),
        ]);

        return ApiResponse::created(
            body: $this->reportScheduleResource($schedule),
            resource: __('report::reports.schedule')
        );
    }

    public function destroySchedule(ReportSchedule $schedule): JsonResponse
    {
        abort_unless($schedule->user_id === auth()->id(), 404);

        $branchId = auth()->user()->assignedToBranch() ? auth()->user()->branch_id : $schedule->branch_id;
        abort_unless(is_null($branchId) || $schedule->branch_id === $branchId, 404);

        return ApiResponse::destroyed(
            destroyed: (bool) $schedule->delete(),
            resource: __('report::reports.schedule')
        );
    }

    public function savedFilters(Request $request, string $report): JsonResponse
    {
        $this->authorizeReport($report);

        $branchId = $this->resolvedBranchId($request);

        $filters = SavedReportFilter::query()
            ->where('report_key', $report)
            ->where('user_id', auth()->id())
            ->when(
                is_null($branchId),
                fn($query) => $query->whereNull('branch_id'),
                fn($query) => $query->where('branch_id', $branchId),
            )
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get()
            ->map(fn(SavedReportFilter $filter) => $this->savedFilterResource($filter))
            ->values();

        return ApiResponse::success([
            'data' => $filters,
        ]);
    }

    public function storeSavedFilter(Request $request, string $report): JsonResponse
    {
        $this->authorizeReport($report);

        $data = $this->validatedSavedFilter($request);
        $branchId = $this->resolvedBranchId($request, $data['branch_id'] ?? null);

        if ($data['is_default'] ?? false) {
            $this->clearDefaultSavedFilters($report, $branchId);
        }

        $filter = SavedReportFilter::query()->create([
            'branch_id' => $branchId,
            'user_id' => auth()->id(),
            'report_key' => $report,
            'name' => $data['name'],
            'filters' => $data['filters'],
            'is_default' => $data['is_default'] ?? false,
        ]);

        return ApiResponse::created(
            body: $this->savedFilterResource($filter),
            resource: __('report::reports.saved_filter')
        );
    }

    public function updateSavedFilter(Request $request, string $report, SavedReportFilter $filter): JsonResponse
    {
        $this->authorizeReport($report);
        $this->authorizeSavedFilter($report, $filter);

        $data = $this->validatedSavedFilter($request);
        $branchId = $this->resolvedBranchId($request, $data['branch_id'] ?? $filter->branch_id);

        if ($data['is_default'] ?? false) {
            $this->clearDefaultSavedFilters($report, $branchId, $filter->id);
        }

        $filter->update([
            'branch_id' => $branchId,
            'name' => $data['name'],
            'filters' => $data['filters'],
            'is_default' => $data['is_default'] ?? false,
        ]);

        return ApiResponse::updated(
            body: $this->savedFilterResource($filter->refresh()),
            resource: __('report::reports.saved_filter')
        );
    }

    public function destroySavedFilter(string $report, SavedReportFilter $filter): JsonResponse
    {
        $this->authorizeReport($report);
        $this->authorizeSavedFilter($report, $filter);

        return ApiResponse::destroyed(
            destroyed: (bool) $filter->delete(),
            resource: __('report::reports.saved_filter')
        );
    }

    private function authorizeReport(string $key): void
    {
        $reportManager = ReportManager::getInstance();

        abort_unless(
            $reportManager->reportExists($key),
            404,
            __("report::messages.report_not_exists", ['report' => $key])
        );

        $report = $reportManager->registeredReports($key);

        abort_unless(
            auth()->user()->can($report->permission()),
            303,
            __("admin::messages.action_unauthorized")
        );
    }

    private function authorizeSavedFilter(string $report, SavedReportFilter $filter): void
    {
        abort_unless(
            $filter->report_key === $report && $filter->user_id === auth()->id(),
            404
        );

        $branchId = auth()->user()->assignedToBranch() ? auth()->user()->branch_id : $filter->branch_id;

        abort_unless(
            is_null($branchId) || $filter->branch_id === $branchId,
            404
        );
    }

    private function validatedSavedFilter(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'filters' => ['required', 'array'],
            'is_default' => ['sometimes', 'boolean'],
            'branch_id' => ['sometimes', 'nullable', 'integer', 'exists:branches,id'],
        ]);
    }

    private function resolvedBranchId(Request $request, ?int $branchId = null): ?int
    {
        $user = auth()->user();

        if ($user->assignedToBranch()) {
            return $user->branch_id;
        }

        $requested = $branchId ?? ($request->integer('branch_id') ?: null);

        // A tenant-scoped admin may only target a branch inside its own tenant.
        // A foreign branch_id is dropped to null so report queries fall back to
        // the caller's own tenant scope instead of reading another tenant's data.
        if ($requested !== null && $user->assignedToTenant() && ! $user->isSuperAdmin()) {
            $ownsBranch = Branch::query()
                ->withOutGlobalScopes()
                ->whereKey($requested)
                ->where('tenant_id', $user->tenant_id)
                ->exists();

            return $ownsBranch ? $requested : null;
        }

        return $requested;
    }

    private function clearDefaultSavedFilters(string $report, ?int $branchId, ?int $exceptId = null): void
    {
        SavedReportFilter::query()
            ->where('report_key', $report)
            ->where('user_id', auth()->id())
            ->when(
                is_null($branchId),
                fn($query) => $query->whereNull('branch_id'),
                fn($query) => $query->where('branch_id', $branchId),
            )
            ->when($exceptId, fn($query) => $query->whereKeyNot($exceptId))
            ->update(['is_default' => false]);
    }

    private function savedFilterResource(SavedReportFilter $filter): array
    {
        return [
            'id' => $filter->id,
            'branch_id' => $filter->branch_id,
            'report_key' => $filter->report_key,
            'name' => $filter->name,
            'filters' => $filter->filters ?: [],
            'is_default' => (bool) $filter->is_default,
            'created_at' => $filter->created_at,
            'updated_at' => $filter->updated_at,
        ];
    }

    private function reportExportResource(ReportExport $export): array
    {
        return [
            'id' => $export->id,
            'branch_id' => $export->branch_id,
            'report_key' => $export->report_key,
            'format' => $export->format,
            'status' => $export->status,
            'file_name' => $export->file_name,
            'file_size' => $export->file_size,
            'error_message' => $export->error_message,
            'expires_at' => $export->expires_at,
            'completed_at' => $export->completed_at,
            'created_at' => $export->created_at,
            'job' => $export->reportJob ? $this->reportJobResource($export->reportJob) : null,
        ];
    }

    private function reportJobResource(ReportJob $job): array
    {
        return [
            'id' => $job->id,
            'branch_id' => $job->branch_id,
            'report_key' => $job->report_key,
            'status' => $job->status,
            'progress' => $job->progress,
            'error_message' => $job->error_message,
            'queued_at' => $job->queued_at,
            'started_at' => $job->started_at,
            'completed_at' => $job->completed_at,
            'created_at' => $job->created_at,
        ];
    }

    private function reportScheduleResource(ReportSchedule $schedule): array
    {
        return [
            'id' => $schedule->id,
            'branch_id' => $schedule->branch_id,
            'report_key' => $schedule->report_key,
            'name' => $schedule->name,
            'frequency' => $schedule->frequency,
            'run_at' => $schedule->run_at,
            'day_of_week' => $schedule->day_of_week,
            'day_of_month' => $schedule->day_of_month,
            'formats' => $schedule->formats ?: [],
            'recipients' => $schedule->recipients ?: [],
            'filters' => $schedule->filters ?: [],
            'is_active' => (bool) $schedule->is_active,
            'last_run_at' => $schedule->last_run_at,
            'next_run_at' => $schedule->next_run_at,
            'created_at' => $schedule->created_at,
        ];
    }

    private function nextScheduleRunAt(array $data): Carbon
    {
        $runAt = Carbon::createFromFormat('H:i', $data['run_at']);
        $nextRun = now()->setTime((int) $runAt->format('H'), (int) $runAt->format('i'));

        if ($data['frequency'] === 'weekly') {
            $targetDay = (int) ($data['day_of_week'] ?? $nextRun->dayOfWeek);
            $nextRun->next($targetDay);
        } elseif ($data['frequency'] === 'monthly') {
            $targetDay = min((int) ($data['day_of_month'] ?? $nextRun->day), $nextRun->daysInMonth);
            $nextRun->day($targetDay);

            if ($nextRun->isPast()) {
                $nextRun->addMonthNoOverflow();
                $nextRun->day(min($targetDay, $nextRun->daysInMonth));
            }
        } elseif ($nextRun->isPast()) {
            $nextRun->addDay();
        }

        return $nextRun;
    }

    private function ownerSummaryCard(string $key, string $label, string $icon, mixed $value, string $color): array
    {
        return compact('key', 'label', 'icon', 'value', 'color');
    }

    private function money(mixed $amount, string $currency): array
    {
        return (new Money((float) ($amount ?? 0), $currency))->toArray();
    }
}
