<?php

namespace Modules\Report\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\ApiController;
use Modules\Report\Services\Gst\GSTCalculationService;


class GstDashboardController extends ApiController
{
    public function __construct(private readonly GSTCalculationService $gst) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless(
            auth()->user()->can('admin.reports.gst_dashboard'),
            403,
            'Unauthorized: GST Dashboard access required.'
        );

        $user     = auth()->user();
        $from     = $request->query('from', $request->query('start_date'));
        $to       = $request->query('to', $request->query('end_date'));
        $currency = $user->assignedToBranch() ? $user->branch->currency : setting('default_currency');
        $branchId = $user->assignedToBranch()
            ? $user->branch_id
            : ($request->query('branch_id') ? (int) $request->query('branch_id') : null);

        $kpis        = $this->gst->dashboardKpis($from, $to, $branchId, $currency);
        $dailyTrend  = $this->gst->dailyTrend($from, $to, $branchId, $currency);
        $monthlyTrend = $this->gst->monthlyTrend($from, $to, $branchId, $currency);
        $byOrderType = $this->gst->byOrderType($from, $to, $branchId);

        return $this->success([
            "kpis"  => [
                [
                    "key"   => "taxable_sales",
                    "label" => "Total Taxable Sales",
                    "value" => $kpis['taxable_sales'],
                    "icon"  => "tabler-report-money",
                    "color" => "primary",
                ],
                [
                    "key"   => "total_gst",
                    "label" => "Total GST Collected",
                    "value" => $kpis['total_gst'],
                    "icon"  => "tabler-tax",
                    "color" => "success",
                ],
                [
                    "key"   => "total_cgst",
                    "label" => "CGST Collected",
                    "value" => $kpis['total_cgst'],
                    "icon"  => "tabler-building-bank",
                    "color" => "info",
                ],
                [
                    "key"   => "total_sgst",
                    "label" => "SGST Collected",
                    "value" => $kpis['total_sgst'],
                    "icon"  => "tabler-building-community",
                    "color" => "warning",
                ],
                [
                    "key"   => "total_igst",
                    "label" => "IGST Collected",
                    "value" => $kpis['total_igst'],
                    "icon"  => "tabler-arrows-transfer-down",
                    "color" => "secondary",
                ],
                [
                    "key"   => "gross_total",
                    "label" => "Gross Total",
                    "value" => $kpis['gross_total'],
                    "icon"  => "tabler-calculator",
                    "color" => "primary",
                ],
                [
                    "key"   => "b2b_orders",
                    "label" => "B2B Orders",
                    "value" => $kpis['b2b_orders'],
                    "icon"  => "tabler-building-store",
                    "color" => "info",
                ],
                [
                    "key"   => "b2c_orders",
                    "label" => "B2C Orders",
                    "value" => $kpis['b2c_orders'],
                    "icon"  => "tabler-users",
                    "color" => "success",
                ],
                [
                    "key"   => "total_orders",
                    "label" => "Total Orders",
                    "value" => $kpis['total_orders'],
                    "icon"  => "tabler-receipt-2",
                    "color" => "secondary",
                ],
            ],
            "daily_trend"   => $dailyTrend,
            "monthly_trend" => $monthlyTrend,
            "by_order_type" => $byOrderType,
            "currency"      => $currency,
        ]);
    }
}
