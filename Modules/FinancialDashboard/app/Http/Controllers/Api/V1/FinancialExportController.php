<?php

namespace Modules\FinancialDashboard\Http\Controllers\Api\V1;

use Excel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Core\Http\Controllers\Controller;
use Modules\FinancialDashboard\Services\FinancialDashboardService;
use Modules\Report\Export\ExcelGlobalExport;
use Modules\Support\ApiResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FinancialExportController extends Controller
{
    public function __construct(protected FinancialDashboardService $service) {}

    public function export(Request $request): JsonResponse|BinaryFileResponse|StreamedResponse
    {
        $data = $request->validate([
            'type'       => ['required', Rule::in(['kpis', 'sales_trend', 'branch_analytics', 'profitability', 'payment_analytics', 'customer_analytics', 'category_analytics'])],
            'start_date' => ['required', 'date'],
            'end_date'   => ['required', 'date', 'after_or_equal:start_date'],
            'branch_id'  => ['sometimes', 'nullable', 'integer', 'exists:branches,id'],
            'format'     => ['sometimes', Rule::in(['json', 'xlsx', 'csv'])],
        ]);

        $branchId = $this->resolvedBranchId($request, $data['branch_id'] ?? null);
        $format   = $data['format'] ?? 'json';
        $type     = $data['type'];
        $start    = $data['start_date'];
        $end      = $data['end_date'];
        $filename = "financial-{$type}-{$start}-{$end}";

        $exportData = match ($type) {
            'kpis'               => $this->service->getKpis($start, $end, $branchId),
            'sales_trend'        => $this->service->getSalesTrend($start, $end, $branchId),
            'branch_analytics'   => $this->service->getBranchAnalytics($start, $end),
            'profitability'      => $this->service->getProfitability($start, $end, $branchId),
            'payment_analytics'  => $this->service->getPaymentAnalytics($start, $end, $branchId),
            'customer_analytics' => $this->service->getCustomerAnalytics($start, $end, $branchId),
            'category_analytics' => $this->service->getCategoryAnalytics($start, $end, $branchId),
        };

        if ($format === 'json') {
            $json = json_encode([
                'type'   => $type,
                'period' => "{$start} to {$end}",
                'data'   => $exportData,
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

            return response()->streamDownload(
                fn() => print($json),
                "{$filename}.json",
                ['Content-Type' => 'application/json']
            );
        }

        [$headings, $collection] = $this->prepareTabular($type, $exportData);

        if ($format === 'csv') {
            return Excel::download(
                new ExcelGlobalExport($headings, $collection),
                "{$filename}.csv",
                \Maatwebsite\Excel\Excel::CSV,
                ['Content-Type' => 'text/csv']
            );
        }

        return Excel::download(
            new ExcelGlobalExport($headings, $collection),
            "{$filename}.xlsx"
        );
    }

    private function prepareTabular(string $type, mixed $data): array
    {
        return match ($type) {
            'sales_trend' => [
                ['Date/Period', 'Net Sales', 'Gross Sales', 'Orders', 'Profit'],
                collect(is_array($data) ? $data : [])->map(fn($r) => [
                    $r['date'] ?? ($r['period'] ?? ''),
                    $r['net_sales'] ?? 0,
                    $r['gross_sales'] ?? 0,
                    $r['total_orders'] ?? 0,
                    $r['profit_total'] ?? 0,
                ]),
            ],
            'branch_analytics' => [
                ['Branch', 'Net Sales', 'Gross Sales', 'Orders', 'Profit', 'AOV', 'Contribution %', 'Profit Margin %'],
                collect(is_array($data) ? $data : [])->map(fn($r) => [
                    $r['branch_name'] ?? '',
                    $r['net_sales'] ?? 0,
                    $r['gross_sales'] ?? 0,
                    $r['total_orders'] ?? 0,
                    $r['profit_total'] ?? 0,
                    $r['avg_order_value'] ?? 0,
                    $r['contribution_pct'] ?? 0,
                    $r['profit_margin_pct'] ?? ($r['profit_margin'] ?? 0),
                ]),
            ],
            'payment_analytics' => [
                ['Method', 'Transactions', 'Total Amount', 'Contribution %'],
                collect(is_array($data) ? $data : [])->map(fn($r) => [
                    $r['method'] ?? '',
                    $r['count'] ?? 0,
                    $r['total'] ?? 0,
                    $r['contribution_pct'] ?? 0,
                ]),
            ],
            'category_analytics' => [
                ['Category ID', 'Items Sold', 'Revenue', 'Cost', 'Profit', 'Avg Margin %', 'Orders'],
                collect(is_array($data) ? $data : [])->map(fn($r) => [
                    $r['category_id'] ?? '',
                    $r['total_quantity'] ?? 0,
                    $r['total_sales'] ?? 0,
                    $r['total_cost'] ?? 0,
                    $r['total_profit'] ?? 0,
                    $r['avg_margin'] ?? 0,
                    $r['total_orders'] ?? 0,
                ]),
            ],
            'customer_analytics' => [
                ['Name', 'Phone', 'Orders', 'Total Spend', 'AOV'],
                collect($data['top_customers'] ?? [])->map(fn($r) => [
                    $r['name'] ?? '',
                    $r['phone'] ?? '',
                    $r['order_count'] ?? 0,
                    $r['total_spend'] ?? 0,
                    $r['avg_order_value'] ?? 0,
                ]),
            ],
            default => [
                ['Metric', 'Value'],
                collect($this->flattenToRows(is_array($data) ? $data : [])),
            ],
        };
    }

    private function flattenToRows(array $data, string $prefix = ''): array
    {
        $rows = [];
        foreach ($data as $key => $value) {
            $label = $prefix ? "{$prefix}.{$key}" : (string) $key;
            if (is_array($value)) {
                $rows = array_merge($rows, $this->flattenToRows($value, $label));
            } else {
                $rows[] = [$label, $value];
            }
        }
        return $rows;
    }

    private function resolvedBranchId(Request $request, ?int $branchId = null): ?int
    {
        if (auth()->user()->assignedToBranch()) {
            return auth()->user()->branch_id;
        }

        return $branchId ?? ($request->integer('branch_id') ?: null);
    }
}
