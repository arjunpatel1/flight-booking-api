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
use Modules\Order\Models\OrderProduct;
use Modules\Pos\Models\KitchenStation;
use Modules\Report\Models\FactKitchenDaily;
use Modules\Report\Models\FactKotDaily;

class BuildKitchenFactTablesJob implements ShouldQueue
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
            $this->buildKitchenDailyFact($businessDate, $branchId);
            $this->buildKotDailyFact($businessDate, $branchId);
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

    protected function buildKitchenDailyFact(Carbon $businessDate, int $branchId): void
    {
        $currency = $this->getBranchCurrency($branchId);
        $stations = KitchenStation::where('branch_id', $branchId)->get();

        foreach ($stations as $station) {
            $this->buildStationFact($businessDate, $branchId, $station, $currency);
        }

        // Also create a summary row for all stations
        $this->buildDailySummary($businessDate, $branchId, $currency);
    }

    protected function buildStationFact(Carbon $businessDate, int $branchId, KitchenStation $station, string $currency): void
    {
        // Get order products assigned to this station
        $orderProducts = OrderProduct::whereHas('order', function ($query) use ($businessDate, $branchId) {
            $query->whereDate('order_date', $businessDate)
                ->where('branch_id', $branchId);
        })
        ->whereHas('kitchenStationOrderProducts', function ($query) use ($station) {
            $query->where('kitchen_station_id', $station->id);
        })
        ->get();

        $totalOrders = $orderProducts->count();
        $completedOrders = $orderProducts->where('status', 'completed')->count();
        $delayedOrders = $orderProducts->where('delay_seconds', '>', 0)->count();

        // Calculate preparation time metrics
        $preparationTimes = $orderProducts->filter(function ($op) {
            return $op->preparation_time_seconds !== null && $op->preparation_time_seconds > 0;
        })->pluck('preparation_time_seconds');

        $avgPreparationTimeMinutes = $preparationTimes->isNotEmpty()
            ? $preparationTimes->avg() / 60
            : null;

        $maxPreparationTimeMinutes = $preparationTimes->isNotEmpty()
            ? $preparationTimes->max() / 60
            : null;

        // Calculate delay metrics
        $delays = $orderProducts->filter(function ($op) {
            return $op->delay_seconds !== null && $op->delay_seconds > 0;
        })->pluck('delay_seconds');

        $avgKotDelayMinutes = $delays->isNotEmpty()
            ? $delays->avg() / 60
            : null;

        // Calculate on-time percentage
        $onTimePercentage = $totalOrders > 0
            ? (($totalOrders - $delayedOrders) / $totalOrders) * 100
            : null;

        // Calculate orders per hour (assuming 12-hour kitchen operation)
        $ordersPerHour = $totalOrders > 0 ? $totalOrders / 12 : null;

        FactKitchenDaily::updateOrCreate(
            [
                'branch_id' => $branchId,
                'business_date' => $businessDate->toDateString(),
                'kitchen_station_id' => $station->id,
            ],
            [
                'currency' => $currency,
                'total_orders' => $totalOrders,
                'completed_orders' => $completedOrders,
                'delayed_orders' => $delayedOrders,
                'avg_preparation_time_minutes' => $avgPreparationTimeMinutes,
                'avg_kot_delay_minutes' => $avgKotDelayMinutes,
                'max_preparation_time_minutes' => $maxPreparationTimeMinutes ? (int) $maxPreparationTimeMinutes : null,
                'orders_per_hour' => $ordersPerHour,
                'on_time_percentage' => $onTimePercentage,
                'max_concurrent_items' => $station->max_concurrent_items,
                'calculated_at' => now(),
            ]
        );
    }

    protected function buildDailySummary(Carbon $businessDate, int $branchId, string $currency): void
    {
        $orderProducts = OrderProduct::whereHas('order', function ($query) use ($businessDate, $branchId) {
            $query->whereDate('order_date', $businessDate)
                ->where('branch_id', $branchId);
        })->get();

        $totalOrders = $orderProducts->count();
        $completedOrders = $orderProducts->where('status', 'completed')->count();
        $delayedOrders = $orderProducts->where('delay_seconds', '>', 0)->count();

        FactKitchenDaily::updateOrCreate(
            [
                'branch_id' => $branchId,
                'business_date' => $businessDate->toDateString(),
                'kitchen_station_id' => null,
            ],
            [
                'currency' => $currency,
                'total_orders' => $totalOrders,
                'completed_orders' => $completedOrders,
                'delayed_orders' => $delayedOrders,
                'calculated_at' => now(),
            ]
        );
    }

    protected function buildKotDailyFact(Carbon $businessDate, int $branchId): void
    {
        $currency = $this->getBranchCurrency($branchId);
        $stations = KitchenStation::where('branch_id', $branchId)->get();

        foreach ($stations as $station) {
            $this->buildStationKotFact($businessDate, $branchId, $station, $currency);
        }
    }

    protected function buildStationKotFact(Carbon $businessDate, int $branchId, KitchenStation $station, string $currency): void
    {
        // Get order products assigned to this station
        $orderProducts = OrderProduct::whereHas('order', function ($query) use ($businessDate, $branchId) {
            $query->whereDate('order_date', $businessDate)
                ->where('branch_id', $branchId);
        })
        ->whereHas('kitchenStationOrderProducts', function ($query) use ($station) {
            $query->where('kitchen_station_id', $station->id);
        })
        ->get();

        // Group by hour
        $byHour = $orderProducts->groupBy(function ($op) {
            return $op->created_at?->hour ?? 0;
        });

        foreach ($byHour as $hour => $hourlyProducts) {
            $totalKots = $hourlyProducts->count();
            $delayedKots = $hourlyProducts->where('delay_seconds', '>', 0)->count();
            $onTimeKots = $totalKots - $delayedKots;

            $kotTimes = $hourlyProducts->filter(function ($op) {
                return $op->preparation_time_seconds !== null && $op->preparation_time_seconds > 0;
            })->pluck('preparation_time_seconds');

            $avgKotTimeMinutes = $kotTimes->isNotEmpty()
                ? $kotTimes->avg() / 60
                : null;

            $delays = $hourlyProducts->filter(function ($op) {
                return $op->delay_seconds !== null && $op->delay_seconds > 0;
            })->pluck('delay_seconds');

            $avgDelayMinutes = $delays->isNotEmpty()
                ? $delays->avg() / 60
                : null;

            $totalQuantity = $hourlyProducts->sum('quantity');

            FactKotDaily::updateOrCreate(
                [
                    'branch_id' => $branchId,
                    'business_date' => $businessDate->toDateString(),
                    'hour' => $hour,
                    'kitchen_station_id' => $station->id,
                    'product_id' => null,
                ],
                [
                    'currency' => $currency,
                    'total_kots' => $totalKots,
                    'on_time_kots' => $onTimeKots,
                    'delayed_kots' => $delayedKots,
                    'avg_kot_time_minutes' => $avgKotTimeMinutes,
                    'avg_delay_minutes' => $avgDelayMinutes,
                    'total_quantity' => $totalQuantity,
                    'calculated_at' => now(),
                ]
            );
        }
    }

    protected function getBranchCurrency(int $branchId): string
    {
        return DB::table('branches')
            ->where('id', $branchId)
            ->value('currency') ?? 'USD';
    }
}
