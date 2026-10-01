<?php

namespace Modules\Printer\Factories\PrintContents;

use Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Modules\Category\Models\Category;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderProduct;
use Modules\Pos\Models\KitchenStation;
use Modules\Pos\Models\KitchenStationOrderProduct;
use Modules\Printer\app\Factories\OrderResourceFactory;
use Modules\Printer\Contracts\PrintContentFactoryInterface;
use Modules\Printer\Models\Printer;
use Modules\User\Enums\DefaultRole;
use Modules\User\Models\User;

class PrintKitchenContentFactory implements PrintContentFactoryInterface
{
    /** @inheritDoc */
    public function relations(): array
    {
        return [
            "customer",
            "products.product.categories",
            "waiter:id,name",
            "table:id,name",
        ];
    }

    /** @inheritDoc */
    public function resource(Order $order): array
    {
        // Whole-order KOT (initial fire / reprint): never print held courses —
        // items in a course that hasn't been fired yet are excluded.
        $products = $order->products->reject(fn($product) => $product->isHeld())->values();

        return $this->resourceForProducts($order, $products);
    }

    public function resourceForProducts(Order $order, Collection $products, ?string $sectionTitle = null): array
    {
        // Incremental and course KOTs can be prepared before the dispatcher
        // reloads the factory relations. Resolve the selected customer here so
        // the prepared payload cannot replace it with a null value.
        $order->loadMissing('customer');

        $data = [
            "order" => OrderResourceFactory::order($order, true),
            "customer" => !is_null($order->customer_id) ? OrderResourceFactory::customer($order) : null,
            "waiter" => !is_null($order->waiter) ? OrderResourceFactory::waiter($order->waiter) : null,
            "table" => !is_null($order->table) ? OrderResourceFactory::table($order->table) : null,
            "kitchens" => [],
            "section_title" => $sectionTitle ?: 'Items',
        ];

        $parsedProducts = $products
            ->map(fn(OrderProduct $product) => OrderResourceFactory::product($product, true))
            ->values();

        $data["products"] = $parsedProducts;

        if ($this->appendStationKitchens($order, $data, $products)) {
            return $data;
        }

        $kitchens = $this->getKitchens($order->branch_id);

        /** @var User $kitchen */
        foreach ($kitchens as $kitchen) {

            $kitchenSlugs = $kitchen->category_slugs;
            $kitchenInfo = [
                "id" => $kitchen->id,
                "name" => $kitchen->name,
                "printer_id" => $kitchen->printer_id,
            ];

            if (empty($kitchenSlugs)) {
                $data["kitchens"][$kitchen->id] = [
                    'kitchen' => $kitchenInfo,
                    "products" => $parsedProducts
                ];
                continue;
            }

            $allowedSlugs = $this->resolveCategoryTreeSlugs($kitchenSlugs);

            $filteredProducts = $products
                ->filter(function (OrderProduct $orderProduct) use ($allowedSlugs) {
                    return $orderProduct->product->categories->isEmpty()
                        || $orderProduct->product->categories
                            ->pluck('slug')
                            ->intersect($allowedSlugs)
                            ->isNotEmpty();
                });

            if ($filteredProducts->isNotEmpty()) {
                $data["kitchens"][$kitchen->id] = [
                    'kitchen' => $kitchenInfo,
                    "products" => $filteredProducts
                        ->map(fn(OrderProduct $filteredProduct) => OrderResourceFactory::product($filteredProduct, true))
                ];
            }
        }

        return $data;
    }

    private function appendStationKitchens(Order $order, array &$data, Collection $products): bool
    {
        $stationItems = KitchenStationOrderProduct::query()
            ->with(['kitchenStation.printer', 'orderProduct.product.categories'])
            ->whereIn('order_product_id', $products->pluck('id'))
            ->get()
            ->filter(fn(KitchenStationOrderProduct $item) => !is_null($item->kitchenStation?->printer));

        if ($stationItems->isEmpty()) {
            return false;
        }

        if ($stationItems->pluck('order_product_id')->unique()->count() < $products->count()) {
            return false;
        }

        foreach ($stationItems->groupBy('kitchen_station_id') as $stationId => $items) {
            $station = $items->first()->kitchenStation;

            $data["kitchens"]["station:{$stationId}"] = [
                'kitchen' => [
                    'id' => $station->id,
                    'name' => $station->name,
                    'printer_id' => $station->printer_id,
                ],
                'products' => $items
                    ->pluck('orderProduct')
                    ->filter()
                    ->map(fn(OrderProduct $orderProduct) => OrderResourceFactory::product($orderProduct, true))
                    ->values(),
            ];
        }

        return !empty($data['kitchens']);
    }

    /**
     * Get kitchens for print
     *
     * @param int $branchId
     * @return Collection
     */
    public function getKitchens(int $branchId): Collection
    {
        return Cache::tags("users")
            ->rememberForever(
                makeCacheKey(
                    [
                        'users',
                        "branch-$branchId",
                        "role-" . DefaultRole::Kitchen->value,
                        "printers"
                    ],
                    false
                ),
                fn() => User::query()
                    // Spatie's role() scope throws when an older tenant has not
                    // seeded the kitchen role. A missing optional print route
                    // must produce an empty collection, never fail an order.
                    ->whereHas('roles', fn($query) => $query
                        // User/permission APIs use the `api` guard in current
                        // installs. Match the stable role name instead of a
                        // legacy hard-coded guard so upgraded tenants retain
                        // kitchen routing.
                        ->where('name', DefaultRole::Kitchen->value))
                    ->where('branch_id', $branchId)
                    ->withOutGlobalBranchPermission()
                    ->whereHas('printer')
                    ->get()
            );
    }

    /**
     * Resolve category tree slugs
     *
     * @param array $slugs
     * @return array
     */
    public function resolveCategoryTreeSlugs(array $slugs): array
    {
        if (empty($slugs)) {
            return [];
        }

        $categories = Category::query()
            ->whereIn('slug', $slugs)
            ->get();

        if ($categories->isEmpty()) {
            return [];
        }

        return Category::query()
            ->where(function ($q) use ($categories) {
                foreach ($categories as $cat) {
                    $q->orWhere(function ($sub) use ($cat) {
                        $sub->where('_lft', '>=', $cat->_lft)
                            ->where('_rgt', '<=', $cat->_rgt);
                    });
                }
            })
            ->pluck('slug')
            ->unique()
            ->values()
            ->all();
    }

    /** @inheritDoc */
    public function printers(int|array $specificIds): array|Printer|null
    {
        $ids = collect(Arr::wrap($specificIds));
        $stationIds = $ids
            ->filter(fn($id) => is_string($id) && str_starts_with($id, 'station:'))
            ->map(fn(string $id) => (int) str($id)->after('station:')->toString())
            ->filter()
            ->values();
        $userIds = $ids
            ->reject(fn($id) => is_string($id) && str_starts_with($id, 'station:'))
            ->map(fn($id) => (int) $id)
            ->filter()
            ->values();

        $stationPrinters = $stationIds->isNotEmpty()
            ? KitchenStation::query()
                ->whereIn('id', $stationIds)
                ->whereNotNull('printer_id')
                ->with('printer')
                ->get()
                ->mapWithKeys(fn(KitchenStation $station) => [
                    "station:{$station->id}" => $station->printer,
                ])
                ->all()
            : [];

        $userPrinters = $userIds->isNotEmpty()
            ? User::query()
            ->whereIn('id', $userIds)
            ->wherehas('printer')
            ->with('printer')
            ->withoutGlobalBranchPermission()
            ->get()
            ->mapWithKeys(function (User $user) {
                return [
                    $user->id => $user->printer
                ];
            })
            ->all()
            : [];

        return $stationPrinters + $userPrinters;
    }
}
