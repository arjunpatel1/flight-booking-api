<?php

namespace Modules\Report\Jobs;

use Carbon\Carbon;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Order\Models\Order;
use Modules\Payment\Enums\PaymentMethod;
use Modules\Pos\Models\PosSession;
use Modules\Report\Models\FactShiftDaily;
use Modules\User\Models\User;

class BuildShiftFactTablesJob implements ShouldQueue
{
    use Batchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 300;
    public array $backoff = [30, 60, 120];

    protected string $date;
    protected ?int $branchId = null;

    public function __construct(string $date, ?int $branchId = null)
    {
        $this->date = $date;
        $this->branchId = $branchId;
    }

    public function handle(): void
    {
        $businessDate = $this->parseDate($this->date);
        $branchIds = $this->getBranchIds($businessDate, $this->branchId);

        foreach ($branchIds as $branchId) {
            $this->buildShiftDailyFact($businessDate, $branchId);
        }
    }

    protected function parseDate(string $date): Carbon
    {
        return Carbon::parse($date)->startOfDay();
    }

    protected function getBranchIds(Carbon $businessDate, ?int $branchId): Collection
    {
        if ($branchId) {
            return collect([$branchId]);
        }

        // Get all branches that have orders on this date
        return Order::whereDate('order_date', $businessDate)
            ->whereNull('branch_id')
            ->pluck('branch_id')
            ->unique()
            ->filter();
    }

    protected function buildShiftDailyFact(Carbon $businessDate, int $branchId): void
    {
        $currency = $this->getBranchCurrency($branchId);

        // Get all POS sessions for the date and branch
        $sessions = PosSession::whereDate('opened_at', $businessDate)
            ->where('branch_id', $branchId)
            ->get();

        foreach ($sessions as $session) {
            $this->buildSessionFact($businessDate, $branchId, $session, $currency);
        }

        // Also build summary for the day (without session)
        $this->buildDailySummary($businessDate, $branchId, $currency);
    }

    protected function buildSessionFact(Carbon $businessDate, int $branchId, PosSession $session, string $currency): void
    {
        $orders = $session->orders()
            ->whereDate('order_date', $businessDate)
            ->get();

        $totalOrders = $orders->count();
        $completedOrders = $orders->where('status', 'completed')->count();
        $cancelledOrders = $orders->where('status', 'cancelled')->count();
        $grossSales = $orders->sum('subtotal');
        $netSales = $orders->sum('total');
        $averageOrderValue = $totalOrders > 0 ? $netSales / $totalOrders : 0;

        // Calculate payment breakdown
        $cashTotal = 0;
        $cardTotal = 0;
        $upiTotal = 0;
        $otherTotal = 0;

        foreach ($orders as $order) {
            foreach ($order->payments as $payment) {
                switch ($payment->method) {
                    case PaymentMethod::Cash:
                        $cashTotal += $payment->amount->amount();
                        break;
                    case PaymentMethod::Card:
                        $cardTotal += $payment->amount->amount();
                        break;
                    case PaymentMethod::UPI:
                        $upiTotal += $payment->amount->amount();
                        break;
                    default:
                        $otherTotal += $payment->amount->amount();
                        break;
                }
            }
        }

        // Calculate duration and performance metrics
        $shiftDurationMinutes = null;
        $ordersPerHour = null;
        $salesPerHour = null;

        if ($session->opened_at && $session->closed_at) {
            $shiftDurationMinutes = $session->opened_at->diffInMinutes($session->closed_at);
            if ($shiftDurationMinutes > 0) {
                $hours = $shiftDurationMinutes / 60;
                $ordersPerHour = $totalOrders / $hours;
                $salesPerHour = $netSales / $hours;
            }
        }

        // Get shift info if available
        $shiftId = $session->shift_id ?? null;
        $shiftStartTime = $session->opened_at?->format('H:i:s');
        $shiftEndTime = $session->closed_at?->format('H:i:s');

        FactShiftDaily::updateOrCreate(
            [
                'branch_id' => $branchId,
                'business_date' => $businessDate->toDateString(),
                'pos_session_id' => $session->id,
            ],
            [
                'shift_id' => $shiftId,
                'user_id' => $session->opened_by,
                'currency' => $currency,
                'shift_start_time' => $shiftStartTime,
                'shift_end_time' => $shiftEndTime,
                'shift_duration_minutes' => $shiftDurationMinutes,
                'total_orders' => $totalOrders,
                'completed_orders' => $completedOrders,
                'cancelled_orders' => $cancelledOrders,
                'gross_sales' => $grossSales,
                'net_sales' => $netSales,
                'average_order_value' => $averageOrderValue,
                'opening_float' => $session->opening_float,
                'declared_cash' => $session->declared_cash,
                'system_cash_sales' => $session->system_cash_sales,
                'cash_over_short' => $session->cash_over_short,
                'cash_total' => $cashTotal,
                'card_total' => $cardTotal,
                'upi_total' => $upiTotal,
                'other_total' => $otherTotal,
                'orders_per_hour' => $ordersPerHour,
                'sales_per_hour' => $salesPerHour,
                'calculated_at' => now(),
            ]
        );
    }

    protected function buildDailySummary(Carbon $businessDate, int $branchId, string $currency): void
    {
        $orders = Order::whereDate('order_date', $businessDate)
            ->where('branch_id', $branchId)
            ->get();

        $totalOrders = $orders->count();
        $completedOrders = $orders->where('status', 'completed')->count();
        $cancelledOrders = $orders->where('status', 'cancelled')->count();
        $grossSales = $orders->sum('subtotal');
        $netSales = $orders->sum('total');
        $averageOrderValue = $totalOrders > 0 ? $netSales / $totalOrders : 0;

        FactShiftDaily::updateOrCreate(
            [
                'branch_id' => $branchId,
                'business_date' => $businessDate->toDateString(),
                'shift_id' => null,
                'user_id' => null,
                'pos_session_id' => null,
            ],
            [
                'currency' => $currency,
                'total_orders' => $totalOrders,
                'completed_orders' => $completedOrders,
                'cancelled_orders' => $cancelledOrders,
                'gross_sales' => $grossSales,
                'net_sales' => $netSales,
                'average_order_value' => $averageOrderValue,
                'calculated_at' => now(),
            ]
        );
    }

    protected function getBranchCurrency(int $branchId): string
    {
        return DB::table('branches')
            ->where('id', $branchId)
            ->value('currency') ?? 'USD';
    }
}
