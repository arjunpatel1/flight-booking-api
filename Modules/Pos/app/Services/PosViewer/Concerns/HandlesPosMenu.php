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

trait HandlesPosMenu
{
    /** @inheritDoc */
    public function getMenuItems(int $menuId, ?string $orderType = null, ?int $tableId = null, ?int $zoneId = null): array
    {
        $this->abortIfOrderTypeNotAllowedForCurrentUser($orderType);
        abort_unless($this->menuSupportsOrderType($menuId, $orderType), 422, __('menu::menus.menu_not_available_for_order_type'));

        $priceTypeId = $this->priceResolver->resolvePriceTypeIdForOrderType($orderType, $tableId, $zoneId);

        return [
            "categories" => $this->getCategories($menuId),
            "products" => $this->getProducts($menuId, $priceTypeId, $orderType, $tableId),
            "pricing" => [
                "price_type_id" => $priceTypeId,
                "order_type" => $orderType,
                "table_id" => $tableId,
                "zone_id" => $zoneId,
            ],
        ];
    }

    /** @inheritDoc */
    public function getMenuProduct(int $menuId, int $productId, ?string $orderType = null, ?int $tableId = null, ?int $zoneId = null): PosProductResource
    {
        try {
            $this->abortIfOrderTypeNotAllowedForCurrentUser($orderType);
            abort_unless($this->menuSupportsOrderType($menuId, $orderType), 422, __('menu::menus.menu_not_available_for_order_type'));

            $priceTypeId = $this->priceResolver->resolvePriceTypeIdForOrderType($orderType, $tableId, $zoneId);
            $product = Product::query()
                ->with([
                    'files',
                    'categories:id,menu_id,name',
                    'options' => fn($query) => $query->with('values'),
                ])
                ->whereHas(
                    'categories',
                    fn($query) => $query->whereIn('id', Category::getFullyActiveCategoryIds($menuId))
                )
                ->whereMenu($menuId)
                ->findOrFail($productId);

            $this->priceResolver->applyToProducts(new EloquentCollection([$product]), $priceTypeId);
            $this->attachRestaurantMemory(
                products: new EloquentCollection([$product]),
                menuId: $menuId,
                waiterId: auth()->id(),
                tableId: $tableId,
                orderType: $orderType,
                shiftName: $this->currentMemoryShift(),
            );

            return new PosProductResource($product);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            throw new \Illuminate\Http\Exceptions\HttpResponseException(
                response()->json([
                    'message' => __('pos::pos.product_not_found'),
                    'error' => 'Product not found in the specified menu',
                ], 404)
            );
        } catch (\Exception $e) {
            \Log::error('Failed to get menu product', [
                'menu_id' => $menuId,
                'product_id' => $productId,
                'error' => $e->getMessage(),
            ]);
            throw new \Illuminate\Http\Exceptions\HttpResponseException(
                response()->json([
                    'message' => __('pos::pos.failed_to_load_product'),
                    'error' => 'An error occurred while loading the product',
                ], 500)
            );
        }
    }

    private function abortIfOrderTypeNotAllowedForCurrentUser(?string $orderType): void
    {
        if (!$orderType) {
            return;
        }

        $user = auth()->user();
        if (!$user || !$user->hasRole(DefaultRole::Waiter->value)) {
            return;
        }

        $assignedOrderTypes = $this->assignedOrderTypesFor($user);
        if (empty($assignedOrderTypes)) {
            return;
        }

        abort_unless(in_array($orderType, $assignedOrderTypes, true), 422, __('order::orders.order_type_not_allowed'));
    }

    private function filterMenusForOrderType(Collection $menus, ?string $orderType): Collection
    {
        if (! $orderType) {
            return $menus;
        }

        return $menus
            ->filter(fn(array $menu) => empty($menu['order_types']) || in_array($orderType, $menu['order_types'], true))
            ->values();
    }

    private function menuSupportsOrderType(int $menuId, ?string $orderType): bool
    {
        $orderTypes = $this->menuOrderTypes($menuId);
        if (is_null($orderTypes)) {
            return false;
        }

        if (! $orderType) {
            return true;
        }

        if (empty($orderTypes)) {
            return true;
        }

        return empty($orderTypes) || in_array($orderType, $orderTypes, true);
    }

    /** @inheritDoc */
}
