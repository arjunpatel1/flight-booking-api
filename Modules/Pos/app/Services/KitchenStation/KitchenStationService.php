<?php

namespace Modules\Pos\Services\KitchenStation;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Branch\Models\Branch;
use Modules\Category\Models\Category;
use Modules\Order\Enums\OrderProductStatus;
use Modules\Order\Models\OrderProduct;
use Modules\Pos\Models\KitchenStation;
use Modules\Pos\Models\KitchenStationOrderProduct;
use Modules\Printer\Models\Printer;
use Modules\User\Enums\DefaultRole;
use Modules\User\Models\User;

class KitchenStationService implements KitchenStationServiceInterface
{
    /** @inheritDoc */
    public function getActiveStations(?int $branchId = null): Collection
    {
        return $this->getStations($branchId, true);
    }

    /** @inheritDoc */
    public function getStations(?int $branchId = null, bool $activeOnly = false): Collection
    {
        $branchId = $this->effectiveBranchId($branchId);

        return KitchenStation::query()
            ->with(['assignedCategories:id,name,slug', 'printer:id,name'])
            ->withoutGlobalActive()
            ->when($branchId, fn($query) => $query->where('branch_id', $branchId))
            ->when($activeOnly, fn($query) => $query->where('is_active', true))
            ->orderBy('display_order')
            ->orderBy('id')
            ->get();
    }

    /** @inheritDoc */
    public function store(array $data): KitchenStation
    {
        $categoryIds = collect($data['categories'] ?? [])->map(fn($id) => (int) $id)->unique()->values()->all();
        unset($data['categories']);

        $data['branch_id'] = $this->effectiveBranchId($data['branch_id'] ?? null);
        $data = $this->normalizeStationPayload($data);

        return DB::transaction(function () use ($data, $categoryIds) {
            $station = KitchenStation::query()->create($data);
            $station->assignedCategories()->sync($this->categorySyncPayload($categoryIds, $station->display_order, $station->prep_time_minutes));

            return $station->fresh(['assignedCategories:id,name,slug', 'printer:id,name']);
        });
    }

    /** @inheritDoc */
    public function update(int $id, array $data): KitchenStation
    {
        $station = $this->findStationForManagement($id);
        $categoryIds = collect($data['categories'] ?? [])->map(fn($id) => (int) $id)->unique()->values()->all();
        unset($data['categories']);

        if (array_key_exists('branch_id', $data)) {
            $data['branch_id'] = $this->effectiveBranchId($data['branch_id']);
        }

        $data = $this->normalizeStationPayload($data);

        return DB::transaction(function () use ($station, $data, $categoryIds) {
            $station->update($data);
            $station->assignedCategories()->sync($this->categorySyncPayload($categoryIds, $station->display_order, $station->prep_time_minutes));

            return $station->fresh(['assignedCategories:id,name,slug', 'printer:id,name']);
        });
    }

    /** @inheritDoc */
    public function destroy(int|array|string $ids): bool
    {
        $query = KitchenStation::query()
            ->withoutGlobalActive()
            ->whereIn('id', parseIds($ids));

        if ($branchId = $this->effectiveBranchId(null)) {
            $query->where('branch_id', $branchId);
        }

        return $query->delete() ?: false;
    }

    /** @inheritDoc */
    public function getFormMeta(?int $branchId = null): array
    {
        $branchId = $this->effectiveBranchId($branchId);
        $branches = auth()->user()?->assignedToBranch()
            ? Branch::query()->whereKey(auth()->user()->branch_id)->get(['id', 'name', 'currency'])
            : Branch::list();

        return [
            'branches' => $branches,
            'categories' => Category::query()
                ->select(['id', 'name', 'slug'])
                ->where('is_active', true)
                ->when($branchId, function ($query) use ($branchId) {
                    $query->whereHas('menu', fn($menuQuery) => $menuQuery->where('branch_id', $branchId));
                })
                ->orderBy('order')
                ->get()
                ->map(fn(Category $category) => [
                    'id' => $category->id,
                    'name' => $category->name,
                    'slug' => $category->slug,
                ]),
            'chefs' => User::list($branchId, DefaultRole::Kitchen),
            'printers' => Printer::list($branchId),
        ];
    }

    /** @inheritDoc */
    public function routeOrderProducts(array $orderProductIds, int $branchId): Collection
    {
        $orderProducts = OrderProduct::with([
            'product.categories',
            'order.customer:id,name',
        ])
            ->whereIn('id', $orderProductIds)
            ->get();

        $routedItems = collect();

        foreach ($orderProducts as $orderProduct) {
            if (!$orderProduct->product) {
                continue;
            }

            $primaryCategory = $orderProduct->product->categories
                ->first(fn(Category $category) => KitchenStation::getStationsForCategory($category->id, $branchId)->isNotEmpty());
            
            if (!$primaryCategory) {
                continue;
            }

            $stations = KitchenStation::getStationsForCategory($primaryCategory->id, $branchId);
            
            if ($stations->isEmpty()) {
                continue;
            }

            $station = $this->selectStationByLoad($stations);
            $prepTime = $this->calculatePrepTime($orderProduct, $station, $primaryCategory);
            
            $stationOrderProduct = KitchenStationOrderProduct::query()
                ->firstOrCreate(
                    [
                        'kitchen_station_id' => $station->id,
                        'order_product_id' => $orderProduct->id,
                    ],
                    [
                        'status' => 'pending',
                        'estimated_completion_at' => now()->addMinutes($prepTime),
                        'prep_time_minutes' => $prepTime,
                        'priority' => $this->calculatePriority($orderProduct),
                    ]
                );

            $routedItems->push($stationOrderProduct);
        }

        return $routedItems;
    }

    /** @inheritDoc */
    public function getStationItems(int $stationId, ?string $status = null): Collection
    {
        $this->abortIfStationNotAllowed($stationId);

        $query = KitchenStationOrderProduct::with([
            'orderProduct' => fn($q) => $q->with([
                'product:id,name,sku',
                'order:id,reference_no,table_id,customer_id,created_at',
                'order.table:id,name',
                'order.customer:id,name'
            ])
        ])
        ->where('kitchen_station_id', $stationId)
        ->orderBy('priority', 'desc')
        ->orderBy('estimated_completion_at');

        if ($status) {
            $query->where('status', $status);
        }

        return $query->get();
    }

    /** @inheritDoc */
    public function startPrep(int $stationOrderProductId, ?int $userId = null, ?int $stationId = null): KitchenStationOrderProduct
    {
        $item = $this->findStationItem($stationOrderProductId, $stationId);

        if ($item->status === 'completed') {
            return $item;
        }
        
        DB::transaction(function () use ($item) {
            $item->update([
                'status' => 'preparing',
                'prep_started_at' => $item->prep_started_at ?: now(),
            ]);

            if ($item->orderProduct->status === OrderProductStatus::Pending) {
                $item->orderProduct->update(['status' => OrderProductStatus::Preparing]);
                $item->orderProduct->order?->recalculateOrderStatus();
            }
        });

        return $item->fresh();
    }

    /** @inheritDoc */
    public function recallItem(int $stationOrderProductId, ?int $userId = null, ?int $stationId = null): KitchenStationOrderProduct
    {
        $item = $this->findStationItem($stationOrderProductId, $stationId);

        abort_if($item->status !== 'completed', 422, __('pos::messages.kitchen_item_must_be_completed_to_recall'));

        // A served item has already left the kitchen — it can't be recalled.
        abort_if(
            $item->orderProduct->status === OrderProductStatus::Served,
            422,
            __('pos::messages.kitchen_item_cannot_recall_served')
        );

        DB::transaction(function () use ($item) {
            $item->update([
                'status' => 'preparing',
                'prep_completed_at' => null,
                'bumped_by' => null,
                'bumped_at' => null,
            ]);

            if (! in_array($item->orderProduct->status, [OrderProductStatus::Cancelled, OrderProductStatus::Refunded], true)) {
                $item->orderProduct->update(['status' => OrderProductStatus::Preparing]);
            }

            $item->orderProduct->order?->recalculateOrderStatus();
        });

        return $item->fresh(['orderProduct.order', 'orderProduct.product']);
    }

    /** @inheritDoc */
    public function completeItem(int $stationOrderProductId, ?int $userId = null, ?string $notes = null, ?int $stationId = null): KitchenStationOrderProduct
    {
        $item = $this->findStationItem($stationOrderProductId, $stationId);

        if ($item->status === 'completed') {
            return $item;
        }

        abort_if($item->status !== 'preparing', 422, __('pos::messages.kitchen_item_must_be_preparing'));

        DB::transaction(function () use ($item, $userId, $notes) {
            $item->update([
                'status' => 'completed',
                'prep_started_at' => $item->prep_started_at ?: now(),
                'prep_completed_at' => now(),
                'bumped_by' => $userId,
                'bumped_at' => now(),
                'notes' => $notes,
            ]);

            if (! in_array($item->orderProduct->status, [OrderProductStatus::Cancelled, OrderProductStatus::Refunded, OrderProductStatus::Served], true)) {
                $item->orderProduct->update(['status' => OrderProductStatus::Ready]);
            }

            $item->orderProduct->order?->recalculateOrderStatus();
        });

        return $item->fresh(['orderProduct.order', 'orderProduct.product']);
    }

    /** @inheritDoc */
    public function completeItems(array $stationOrderProductIds, ?int $userId = null, ?string $notes = null, ?int $stationId = null): Collection
    {
        if ($stationId) {
            $this->abortIfStationNotAllowed($stationId);
        }

        $ids = collect($stationOrderProductIds)
            ->map(fn($id) => (int) $id)
            ->filter()
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        DB::transaction(function () use ($ids, $userId, $notes, $stationId) {
            $items = KitchenStationOrderProduct::query()
                ->when($stationId, fn($query) => $query->where('kitchen_station_id', $stationId))
                ->whereIn('id', $ids)
                ->where('status', 'preparing')
                ->with(['orderProduct.order'])
                ->get();

            foreach ($items as $item) {
                $item->update([
                    'status' => 'completed',
                    'prep_started_at' => $item->prep_started_at ?: now(),
                    'prep_completed_at' => now(),
                    'bumped_by' => $userId,
                    'bumped_at' => now(),
                    'notes' => $notes,
                ]);

                if (! in_array($item->orderProduct->status, [OrderProductStatus::Cancelled, OrderProductStatus::Refunded, OrderProductStatus::Served], true)) {
                    $item->orderProduct->update(['status' => OrderProductStatus::Ready]);
                }

                $item->orderProduct->order?->recalculateOrderStatus();
            }
        });

        return KitchenStationOrderProduct::query()
            ->with(['orderProduct.order', 'orderProduct.product'])
            ->whereIn('id', $ids)
            ->get();
    }

    /** @inheritDoc */
    public function getStationStats(int $stationId, ?string $from = null, ?string $to = null): array
    {
        $this->abortIfStationNotAllowed($stationId);

        $query = KitchenStationOrderProduct::where('kitchen_station_id', $stationId);

        if ($from) {
            $query->where('created_at', '>=', $from);
        }

        if ($to) {
            $query->where('created_at', '<=', $to);
        }

        $items = $query->get();

        return [
            'total_items' => $items->count(),
            'completed_items' => $items->where('status', 'completed')->count(),
            'preparing_items' => $items->where('status', 'preparing')->count(),
            'pending_items' => $items->where('status', 'pending')->count(),
            'average_prep_time' => $items->whereNotNull('prep_completed_at')
                ->avg(fn($item) => $item->prep_completed_at->diffInMinutes($item->prep_started_at ?? $item->created_at)),
            'delayed_items' => $items->filter(fn($item) => $item->isDelayed())->count(),
        ];
    }

    /** @inheritDoc */
    public function updateItemPriority(int $stationOrderProductId, int $priority, ?int $stationId = null): KitchenStationOrderProduct
    {
        $item = $this->findStationItem($stationOrderProductId, $stationId);
        $item->update(['priority' => $priority]);
        
        return $item->fresh();
    }

    /** @inheritDoc */
    public function getDelayedItems(?int $branchId = null): Collection
    {
        $branchId = $this->effectiveBranchId($branchId);

        $query = KitchenStationOrderProduct::with([
            'kitchenStation:id,name',
            'orderProduct' => fn($q) => $q->with([
                'product:id,name',
                'order:id,reference_no'
            ])
        ])
        ->where('status', '!=', 'completed')
        ->whereNotNull('estimated_completion_at')
        ->where('estimated_completion_at', '<', now());

        if ($branchId) {
            $query->whereHas('kitchenStation', fn($q) => $q->where('branch_id', $branchId));
        }

        return $query->get();
    }

    /** @inheritDoc */
    public function autoBumpCompletedItems(?int $branchId = null): int
    {
        $branchId = $this->effectiveBranchId($branchId);

        $query = KitchenStationOrderProduct::where('status', 'completed')
            ->whereNotNull('prep_completed_at')
            ->whereNull('bumped_at');

        if ($branchId) {
            $query->whereHas('kitchenStation', fn($q) => $q->where('branch_id', $branchId));
        }

        // Get stations with auto-bump settings
        $stations = KitchenStation::where('auto_bump_minutes', '>', 0)
            ->when($branchId, fn($q) => $q->where('branch_id', $branchId))
            ->get();

        $bumpedCount = 0;

        foreach ($stations as $station) {
            $cutoffTime = now()->subMinutes($station->auto_bump_minutes);
            
            $itemsToBump = $query->clone()
                ->where('kitchen_station_id', $station->id)
                ->where('prep_completed_at', '<=', $cutoffTime)
                ->get();

            foreach ($itemsToBump as $item) {
                $item->update([
                    'bumped_at' => now(),
                    'notes' => trim(($item->notes ?? '') . ' ' . __('pos::pos_viewer.kitchen_auto_bumped_note')),
                ]);
                $bumpedCount++;
            }
        }

        return $bumpedCount;
    }

    /**
     * Calculate prep time for an item.
     */
    private function calculatePrepTime(OrderProduct $orderProduct, KitchenStation $station, Category $category): int
    {
        // Check for category-specific override
        $stationCategory = $station->categories()
            ->where('category_id', $category->id)
            ->first();

        if ($stationCategory?->prep_time_override) {
            return $stationCategory->prep_time_override;
        }

        // Use station default prep time
        if ($station->prep_time_minutes) {
            return $station->prep_time_minutes;
        }

        // Fallback to category prep time if available
        return $category->prep_time_minutes ?? 15;
    }

    /**
     * Calculate priority for an item.
     */
    private function calculatePriority(OrderProduct $orderProduct): int
    {
        $priority = 1;

        $customer = $orderProduct->order->customer;

        // Keep VIP support optional so KDS does not assume a customer column.
        if ($customer && array_key_exists('is_vip', $customer->getAttributes()) && $customer->getAttribute('is_vip')) {
            $priority += 3;
        }

        // Rush orders get higher priority
        if ($orderProduct->order->is_rush) {
            $priority += 2;
        }

        // Older orders get slightly higher priority
        $ageInMinutes = $orderProduct->created_at->diffInMinutes(now());
        if ($ageInMinutes > 30) {
            $priority += 1;
        }

        return $priority;
    }

    private function selectStationByLoad(Collection $stations): KitchenStation
    {
        return $stations
            ->map(function (KitchenStation $station) {
                $station->active_items_count = KitchenStationOrderProduct::query()
                    ->where('kitchen_station_id', $station->id)
                    ->whereIn('status', ['pending', 'preparing'])
                    ->count();

                return $station;
            })
            ->sortBy([
                ['active_items_count', 'asc'],
                ['display_order', 'asc'],
            ])
            ->first();
    }

    private function findStationItem(int $stationOrderProductId, ?int $stationId = null): KitchenStationOrderProduct
    {
        if ($stationId) {
            $this->abortIfStationNotAllowed($stationId);
        }

        return KitchenStationOrderProduct::query()
            ->when($stationId, fn($query) => $query->where('kitchen_station_id', $stationId))
            ->with(['orderProduct.order', 'orderProduct.product'])
            ->findOrFail($stationOrderProductId);
    }

    private function findStationForManagement(int $id): KitchenStation
    {
        $station = KitchenStation::query()
            ->withoutGlobalActive()
            ->with(['assignedCategories:id,name,slug', 'printer:id,name'])
            ->findOrFail($id);

        $this->abortIfBranchMismatch($station->branch_id);

        return $station;
    }

    private function abortIfStationNotAllowed(int $stationId): void
    {
        $station = KitchenStation::query()->withoutGlobalActive()->findOrFail($stationId);
        $this->abortIfBranchMismatch($station->branch_id);
    }

    private function abortIfBranchMismatch(?int $branchId): void
    {
        $user = auth()->user();
        abort_if($user?->assignedToBranch() && (int) $branchId !== (int) $user->branch_id, 403);
    }

    private function effectiveBranchId(?int $branchId): ?int
    {
        $user = auth()->user();

        return $user?->assignedToBranch() ? (int) $user->branch_id : $branchId;
    }

    private function normalizeStationPayload(array $data): array
    {
        foreach (['name', 'description'] as $attribute) {
            if (!array_key_exists($attribute, $data) || is_array($data[$attribute])) {
                continue;
            }

            $data[$attribute] = [setting('default_locale', 'en') => $data[$attribute]];
        }

        return $data;
    }

    private function categorySyncPayload(array $categoryIds, int $priority, int $prepTime): array
    {
        return collect($categoryIds)
            ->mapWithKeys(fn(int $categoryId) => [
                $categoryId => [
                    'priority' => $priority,
                    'prep_time_override' => $prepTime,
                ],
            ])
            ->all();
    }
}
