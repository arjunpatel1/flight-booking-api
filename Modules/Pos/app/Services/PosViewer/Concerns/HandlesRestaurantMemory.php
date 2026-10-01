<?php

namespace Modules\Pos\Services\PosViewer\Concerns;

use Darryldecode\Cart\Exceptions\InvalidConditionException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Branch\Models\Branch;
use Modules\Cart\Facades\Cart;
use Modules\Category\Models\Category;
use Modules\Discount\Models\Discount;
use Modules\Menu\Models\Menu;
use Modules\Order\Enums\OrderPaymentStatus;
use Modules\Order\Enums\OrderProductStatus;
use Modules\Order\Enums\OrderStatus;
use Modules\Order\Enums\OrderType;
use Modules\Order\Models\Order;
use Modules\Order\Support\OrderActionPolicy;
use Modules\Pos\Enums\PosCashDirection;
use Modules\Pos\Enums\PosCashReason;
use Modules\Pos\Enums\PosSessionStatus;
use Modules\Pos\Models\PosRegister;
use Modules\Pos\Models\PosSession;
use Modules\Pos\Models\PosTerminalDevice;
use Modules\Pos\Transformers\Api\V1\Pos\PosCategoryResource;
use Modules\Pos\Transformers\Api\V1\Pos\PosProductResource;
use Modules\Printer\Enum\PrintJobStatus;
use Modules\Printer\Models\PrintJob;
use Modules\Product\Models\Product;
use Modules\SeatingPlan\Enums\ReservationStatus;
use Modules\SeatingPlan\Enums\TableStatus;
use Modules\SeatingPlan\Models\Table;
use Modules\SeatingPlan\Models\TableReservation;
use Modules\Support\ActionPolicy;
use Modules\Support\Money;
use Modules\User\Enums\DefaultRole;
use Modules\User\Models\User;
use Modules\Core\Intelligence\ContextEngine;
use Modules\Core\Intelligence\DecisionEngine;
use Modules\Core\Intelligence\HealthScore;
use Modules\Core\Intelligence\MemoryScore;
use Modules\Core\Intelligence\PredictionEngine;

trait HandlesRestaurantMemory
{
    private function attachRestaurantMemory(
        EloquentCollection $products,
        int $menuId,
        ?int $waiterId,
        ?int $tableId,
        ?string $orderType,
        string $shiftName,
    ): void {
        $productIds = $products
            ->pluck('id')
            ->map(fn($id) => (int) $id)
            ->filter()
            ->values()
            ->all();

        if (empty($productIds)) {
            return;
        }

        $windowDays = max((int) setting('pos_memory_window_days', 90), 7);
        $pairingWindowDays = max((int) setting('pos_memory_pairing_window_days', 60), 7);
        $pairingRowLimit = max((int) setting('pos_memory_pairing_row_limit', 5000), 1000);
        $since = now()->subDays($windowDays);

        $base = DB::table('order_products as op')
            ->join('orders as o', 'o.id', '=', 'op.order_id')
            ->whereIn('op.product_id', $productIds)
            ->whereIn('o.status', [OrderStatus::Served->value, OrderStatus::Completed->value])
            ->where('op.created_at', '>=', $since);

        if ($orderType) {
            $base->where('o.type', $orderType);
        }

        $restaurantCounts = (clone $base)
            ->select('op.product_id', DB::raw('SUM(op.quantity) as quantity'), DB::raw('COUNT(DISTINCT op.order_id) as order_count'))
            ->groupBy('op.product_id')
            ->get()
            ->keyBy(fn($row) => (int) $row->product_id);

        $waiterCounts = $waiterId
            ? (clone $base)
                ->where(fn($query) => $query
                    ->where('o.waiter_id', $waiterId)
                    ->orWhere('o.created_by', $waiterId))
                ->select('op.product_id', DB::raw('SUM(op.quantity) as quantity'))
                ->groupBy('op.product_id')
                ->get()
                ->keyBy(fn($row) => (int) $row->product_id)
            : collect();

        $tableCounts = $tableId
            ? (clone $base)
                ->where('o.table_id', $tableId)
                ->select('op.product_id', DB::raw('SUM(op.quantity) as quantity'))
                ->groupBy('op.product_id')
                ->get()
                ->keyBy(fn($row) => (int) $row->product_id)
            : collect();

        $shiftCounts = $this->memoryShiftCounts($base);
        $pairings = $this->memoryPairings(
            base: $base,
            productIds: $productIds,
            since: now()->subDays($pairingWindowDays),
            rowLimit: $pairingRowLimit,
        );

        foreach ($products as $product) {
            $productId = (int) $product->id;
            $restaurantQuantity = (int) ($restaurantCounts[$productId]->quantity ?? 0);
            $waiterQuantity = (int) ($waiterCounts[$productId]->quantity ?? 0);
            $tableQuantity = (int) ($tableCounts[$productId]->quantity ?? 0);
            $shiftQuantity = (int) ($shiftCounts[$productId][$shiftName] ?? 0);
            $productPairings = $pairings[$productId] ?? [];
            $pairingScore = array_sum(array_column($productPairings, 'score'));

            // Composite product-recommendation score from the single RME weighting
            // source in the NexDine Intelligence Layer (no local weight table).
            $scores = MemoryScore::compose(
                restaurantQuantity: $restaurantQuantity,
                shiftQuantity: $shiftQuantity,
                waiterQuantity: $waiterQuantity,
                tableQuantity: $tableQuantity,
                pairingScore: $pairingScore,
            );

            $product->setAttribute('pos_memory', [
                'version' => 1,
                'shift' => $shiftName,
                ...$scores,
                'restaurant_quantity' => $restaurantQuantity,
                'waiter_quantity' => $waiterQuantity,
                'table_quantity' => $tableQuantity,
                'pairing_product_ids' => array_column($productPairings, 'product_id'),
                'pairings' => $productPairings,
            ]);
        }
    }

    private function memoryShiftCounts(\Illuminate\Database\Query\Builder $base): Collection
    {
        $hourExpression = DB::connection()->getDriverName() === 'sqlite'
            ? "CAST(strftime('%H', o.created_at) AS INTEGER)"
            : 'HOUR(o.created_at)';

        $rows = (clone $base)
            ->select('op.product_id', DB::raw("{$hourExpression} as order_hour"), DB::raw('SUM(op.quantity) as quantity'))
            ->groupBy('op.product_id', DB::raw($hourExpression))
            ->get();

        $counts = [];
        foreach ($rows as $row) {
            $productId = (int) $row->product_id;
            $shift = $this->memoryShiftForHour((int) $row->order_hour);
            $counts[$productId][$shift] = ($counts[$productId][$shift] ?? 0) + (int) $row->quantity;
        }

        return collect($counts);
    }

    private function memoryPairings(
        \Illuminate\Database\Query\Builder $base,
        array $productIds,
        \Carbon\CarbonInterface $since,
        int $rowLimit,
    ): array {
        $rows = (clone $base)
            ->where('op.created_at', '>=', $since)
            ->select(
                'op.order_id',
                'op.product_id',
                DB::raw('MAX(op.created_at) as last_added_at')
            )
            ->groupBy('op.order_id', 'op.product_id')
            ->orderByDesc('last_added_at')
            ->limit($rowLimit)
            ->get();

        $validProducts = array_fill_keys($productIds, true);
        $byOrder = [];
        foreach ($rows as $row) {
            $orderId = (int) $row->order_id;
            $productId = (int) $row->product_id;
            if (! isset($validProducts[$productId])) {
                continue;
            }
            $byOrder[$orderId][$productId] = true;
        }

        $pairCounts = [];
        foreach ($byOrder as $orderProducts) {
            $ids = array_keys($orderProducts);
            if (count($ids) < 2) {
                continue;
            }
            foreach ($ids as $sourceId) {
                foreach ($ids as $targetId) {
                    if ($sourceId === $targetId) {
                        continue;
                    }
                    $pairCounts[$sourceId][$targetId] = ($pairCounts[$sourceId][$targetId] ?? 0) + 1;
                }
            }
        }

        $pairings = [];
        foreach ($pairCounts as $sourceId => $targets) {
            arsort($targets);
            $pairings[$sourceId] = collect($targets)
                ->take(5)
                ->map(fn(int $score, int $productId) => [
                    'product_id' => $productId,
                    'score' => $score,
                ])
                ->values()
                ->all();
        }

        return $pairings;
    }

    private function currentMemoryShift(): string
    {
        // Shift comes from the single NexDine Intelligence Layer context engine.
        return (new ContextEngine())->current()->shift;
    }

    private function memoryShiftForHour(int $hour): string
    {
        return ContextEngine::shiftForHour($hour);
    }

    private function normalizedMemoryOrderType(?string $orderType): ?string
    {
        if (! is_string($orderType) || trim($orderType) === '') {
            return null;
        }

        $value = strtolower(str_replace(['-', ' '], '_', trim($orderType)));

        return OrderType::tryFrom($value)?->value;
    }

    private function shouldCachePosProductList(): bool
    {
        $store = (string) config('cache.default');
        $driver = (string) config("cache.stores.$store.driver", $store);

        return ! in_array($driver, ['file', 'filesystem', 'array'], true);
    }

    private function menuOrderTypes(int $menuId): ?array
    {
        return $this->cacheWithTags(['menus'])
            ->rememberForever(
                makeCacheKey(['menus', "menu-$menuId", 'order-types']),
                function () use ($menuId) {
                    $menu = Menu::query()
                        ->whereKey($menuId)
                        ->first(['id', 'order_types']);

                    return $menu ? ($menu->order_types ?: []) : null;
                }
            );
    }

    private function cacheWithTags(array $tags): mixed
    {
        if ($this->supportsCacheTags()) {
            return Cache::tags($tags);
        }

        return Cache::store();
    }

    private function supportsCacheTags(): bool
    {
        return method_exists(Cache::getStore(), 'tags');
    }
}
