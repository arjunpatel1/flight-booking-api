<?php

namespace Modules\Pos\Services\Pos;

use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Cache;
use Modules\Branch\Models\Branch;
use Modules\Category\Models\Category;
use Modules\Menu\Models\Menu;
use Modules\Order\Enums\OrderType;
use Modules\Order\Services\Order\OrderServiceInterface;
use Modules\Order\Transformers\Api\V1\Kitchen\KitchenOrderResource;
use Modules\Payment\Enums\PaymentMethod;
use Modules\Pos\Enums\PosCashDirection;
use Modules\Pos\Enums\PosCashReason;
use Modules\Pos\Models\PosRegister;
use Modules\Pos\Transformers\Api\V1\Pos\PosCategoryResource;
use Modules\Pos\Transformers\Api\V1\Pos\PosProductResource;
use Modules\Product\Models\Product;
use Modules\Tax\Models\Tax;
use Modules\User\Enums\DefaultRole;

class PosService implements PosServiceInterface
{
    /** @inheritDoc */
    public function get(): array
    {
        $user = auth()->user();

        $branchId = $user->assignedToBranch() ? $user->branch_id : Branch::main()->first()?->id;
        $menu = Menu::getActiveMenu($branchId, true);

        abort_if(is_null($menu), 400, __("pos::messages.menu_is_not_active"));

        $orderTypes = $menu->branch->order_types ?: [];

        if ($user->hasRole(DefaultRole::Waiter->value) && in_array(OrderType::DineIn->value, $orderTypes)) {
            $orderTypes = [OrderType::DineIn->value];
        }

        $directions = PosCashDirection::toArrayTrans([PosCashDirection::Adjust->value]);
        $reasons = [];

        foreach ($directions as $direction) {
            $reasons[$direction['id']] = array_map(
                fn(PosCashReason $reason) => $reason->toTrans(),
                PosCashReason::getForManageCashMovement(PosCashDirection::from($direction['id']))
            );
        }

        $branchPaymentMethods = $menu->branch->payment_methods ?? [];

        // If string → wrap in array
        if (is_string($branchPaymentMethods)) {
            $branchPaymentMethods = [$branchPaymentMethods];
        }

        return [
            "menu" => [
                "id" => $menu->id,
                "name" => $menu->name,
            ],
            "branch" => [
                "id" => $menu->branch_id,
                "name" => $menu->branch->name,
                "currency" => $menu->branch->currency,
            ],
            "categories" => $this->getCategories($menu),
            "products" => $this->getProducts($menu),
            "taxes" => Tax::list($menu->branch_id, true),
            "order_types" => array_values(array_filter(
                OrderType::toArrayTrans(),
                fn($orderType) => in_array($orderType['id'], $orderTypes)
            )),
            "payment_methods" => array_values(array_filter(
                PaymentMethod::toArrayTrans(),
                fn($orderType) => in_array(
                    $orderType['id'],
                    $branchPaymentMethods ?: []
                )
            )),
            "pos_registers" => PosRegister::list($menu->branch_id),
            "pos_cash_movements_meta" => ["directions" => $directions, "reasons" => $reasons]
        ];
    }

    /**
     * Get tree categories for pos
     *
     * @param Menu $menu
     * @return AnonymousResourceCollection
     */
    private function getCategories(Menu $menu): AnonymousResourceCollection
    {
        return Cache::tags("categories")
            ->rememberForever(
                makeCacheKey([
                    'categories',
                    "menu-{$menu->id}",
                    'pos_tree'
                ]),
                fn() => PosCategoryResource::collection(
                    Category::query()
                        ->with(["childrenRecursive", "files"])
                        ->whereNull("parent_id")
                        ->whereMenu($menu->id)
                        ->orderBy("order")
                        ->get()
                )
            );
    }

    /**
     * Get products for pos
     *
     * @param Menu $menu
     * @return AnonymousResourceCollection
     */
    private function getProducts(Menu $menu): AnonymousResourceCollection
    {
        return Cache::tags("products")
            ->rememberForever(
                makeCacheKey([
                    'products',
                    "menu-{$menu->id}",
                    'pos'
                ]),
                fn() => PosProductResource::collection(
                    Product::query()
                        ->with([
                            "files",
                            "categories:id,menu_id",
                            "taxes",
                            "options" => fn($query) => $query->with("values")
                        ])
                        ->whereMenu($menu->id)
                        ->latest()
                        ->orderBy("is_available", "asc")
                        ->get()
                )
            );
    }

    /** @inheritDoc */
    public function kitchenViewer(): array
    {
        $orderService = app(OrderServiceInterface::class);
        $branch = auth()->user()->effective_branch;

        return [
            "orders" => KitchenOrderResource::collection($orderService->getOrdersForKitchen()),
            "order_types" => array_filter(
                OrderType::toArrayTrans(),
                fn($orderType) => in_array($orderType['id'], $branch->order_types ?: [])
            ),
        ];
    }
}
