<?php

namespace Modules\Report\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Modules\Core\Http\Controllers\Controller;
use Modules\Order\Enums\OrderStatus;
use Modules\Order\Enums\OrderType;
use Modules\Report\Jobs\GenerateMonthlySalesReportJob;
use Modules\Report\Models\MonthlySalesReport;
use Modules\Support\ApiResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class MonthlySalesReportController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $data = $request->validate([
            'month' => ['required', 'integer', 'between:1,12'],
            'year' => ['required', 'integer', 'between:2020,2100'],
            'filters' => ['sometimes', 'array'],
            'filters.order_type' => ['nullable', 'string', Rule::in(OrderType::values())],
            'filters.order_status' => ['nullable', 'string', Rule::in(OrderStatus::values())],
            'filters.payment_method' => ['nullable', 'string', 'max:50'],
            'filters.waiter_id' => ['nullable', 'integer', 'min:1'],
            'filters.table_id' => ['nullable', 'integer', 'min:1'],
            'filters.floor_id' => ['nullable', 'integer', 'min:1'],
            'filters.zone_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $filters = $this->cleanFilters($data['filters'] ?? []);

        return ApiResponse::success([
            'report' => $this->resource(
                $this->findReport((int) $data['month'], (int) $data['year']),
                $filters
            ),
        ]);
    }

    public function generate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'month' => ['required', 'integer', 'between:1,12'],
            'year' => ['required', 'integer', 'between:2020,2100'],
            'force' => ['sometimes', 'boolean'],
            'filters' => ['sometimes', 'array'],
            'filters.order_type' => ['nullable', 'string', Rule::in(OrderType::values())],
            'filters.order_status' => ['nullable', 'string', Rule::in(OrderStatus::values())],
            'filters.payment_method' => ['nullable', 'string', 'max:50'],
            'filters.waiter_id' => ['nullable', 'integer', 'min:1'],
            'filters.table_id' => ['nullable', 'integer', 'min:1'],
            'filters.floor_id' => ['nullable', 'integer', 'min:1'],
            'filters.zone_id' => ['nullable', 'integer', 'min:1'],
        ]);

        // Convert string "true"/"false"/"1"/"0" to actual boolean for the force parameter
        $force = !empty($data['force']) && filter_var($data['force'], FILTER_VALIDATE_BOOLEAN);
        $filters = $this->cleanFilters($data['filters'] ?? []);

        $report = $this->findReport((int) $data['month'], (int) $data['year']);

        if ($report && $report->status === 'generated' && !$force) {
            return ApiResponse::success(['report' => $this->resource($report, $filters)]);
        }

        if ($report && in_array($report->status, ['pending', 'generating'], true)) {
            if (!$this->isStale($report)) {
                return ApiResponse::success(['report' => $this->resource($report->refresh(), $filters)]);
            }

            $report->update([
                'generated_by' => auth()->id(),
                'filters' => $filters,
                'status' => 'pending',
                'progress' => 1,
                'error_message' => null,
            ]);

            GenerateMonthlySalesReportJob::dispatch($report->id);

            return ApiResponse::success(['report' => $this->resource($report->refresh(), $filters)]);
        }

        $branchId = auth()->user()->assignedToBranch() ? auth()->user()->branch_id : null;

        $report = MonthlySalesReport::query()->updateOrCreate(
            [
                'branch_id' => $branchId,
                'month' => (int) $data['month'],
                'year' => (int) $data['year'],
            ],
            [
                'branch_id' => $branchId,
                'generated_by' => auth()->id(),
                'filters' => $filters,
                'status' => 'pending',
                'progress' => 1,
                'error_message' => null,
                'disk' => 'local',
            ]
        );

        GenerateMonthlySalesReportJob::dispatch($report->id);

        return ApiResponse::success(['report' => $this->resource($report->refresh(), $filters)]);
    }

    public function download(MonthlySalesReport $report): BinaryFileResponse
    {
        abort_unless($this->canAccess($report), 404);
        abort_unless($report->status === 'generated' && $report->file_path && Storage::disk($report->disk)->exists($report->file_path), 404);

        return response()->download(
            Storage::disk($report->disk)->path($report->file_path),
            $report->file_name ?? sprintf('monthly-sales-%04d-%02d.xlsx', $report->year, $report->month)
        );
    }

    public function view(MonthlySalesReport $report): BinaryFileResponse
    {
        abort_unless($this->canAccess($report), 404);
        abort_unless($report->status === 'generated' && $report->file_path && Storage::disk($report->disk)->exists($report->file_path), 404);

        return response()->file(Storage::disk($report->disk)->path($report->file_path));
    }

    private function findReport(int $month, int $year): ?MonthlySalesReport
    {
        return MonthlySalesReport::query()
            ->when(
                auth()->user()->assignedToBranch(),
                fn($query) => $query->where('branch_id', auth()->user()->branch_id),
                // Tenant-level (branch_id NULL) reports carry no tenant column, so
                // two tenants' owners would otherwise share the same NULL-branch
                // row. Scope by the generating user to keep them isolated.
                fn($query) => $query->whereNull('branch_id')->where('generated_by', auth()->id())
            )
            ->where('month', $month)
            ->where('year', $year)
            ->first();
    }

    private function canAccess(MonthlySalesReport $report): bool
    {
        if (auth()->user()->assignedToBranch()) {
            return $report->branch_id === auth()->user()->branch_id;
        }

        // A tenant-level report is reachable only by the user that generated it,
        // never by another tenant's admin sharing the NULL-branch namespace.
        return $report->branch_id === null && $report->generated_by === auth()->id();
    }

    private function isStale(MonthlySalesReport $report): bool
    {
        $minutes = max(1, (int) config('report.monthly_sales_report.stale_after_minutes', 5));

        return $report->updated_at?->lt(now()->subMinutes($minutes)) ?? true;
    }

    private function cleanFilters(array $filters): array
    {
        $allowed = [
            'order_type',
            'order_status',
            'payment_method',
            'waiter_id',
            'table_id',
            'floor_id',
            'zone_id',
        ];

        $clean = [];
        foreach ($allowed as $key) {
            if (!array_key_exists($key, $filters) || $filters[$key] === null || $filters[$key] === '') {
                continue;
            }

            $clean[$key] = in_array($key, ['waiter_id', 'table_id', 'floor_id', 'zone_id'], true)
                ? (int) $filters[$key]
                : (string) $filters[$key];
        }

        ksort($clean);

        return $clean;
    }

    private function filtersMatch(array $storedFilters, array $currentFilters): bool
    {
        ksort($storedFilters);
        ksort($currentFilters);

        return $storedFilters === $currentFilters;
    }

    private function resource(?MonthlySalesReport $report, array $currentFilters = []): ?array
    {
        if (!$report) {
            return null;
        }

        $storedFilters = $this->cleanFilters($report->filters ?: []);
        $filtersMatch = $this->filtersMatch($storedFilters, $currentFilters);

        return [
            'id' => $report->id,
            'month' => $report->month,
            'year' => $report->year,
            'filters' => $storedFilters,
            'filters_match' => $filtersMatch,
            'requires_regeneration' => !$filtersMatch && $report->status === 'generated',
            'status' => $report->status,
            'progress' => $report->progress,
            'total_orders' => $report->total_orders,
            'total_revenue' => (float) $report->total_revenue,
            'currency' => $report->currency,
            'generated_at' => $report->generated_at?->toDateTimeString(),
            'file_name' => $report->file_name,
            'error_message' => $report->error_message,
        ];
    }
}
