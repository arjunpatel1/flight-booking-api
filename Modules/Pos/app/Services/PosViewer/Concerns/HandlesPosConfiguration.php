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

trait HandlesPosConfiguration
{
    /** @inheritDoc
     * @throws InvalidConditionException
     */
    public function getConfiguration(?int $branchId = null, bool $lightweight = false): array
    {
        $user = auth()->user();
        $isWaiter = $user->hasRole(DefaultRole::Waiter->value);
        $assignedOrderTypes = $this->assignedOrderTypesFor($user);
        $cacheKey = makeCacheKey([
            'pos-configuration',
            'cart-' . (request()->route('cartId') ?: 'none'),
            'branch-' . ($branchId ?? 'all'),
            'user-' . $user->id,
            'user-updated-' . ($user->updated_at?->timestamp ?? 'none'),
            'user-order-types-' . md5(json_encode($assignedOrderTypes)),
            'lightweight-' . ($lightweight ? '1' : '0'),
            'role-' . ($isWaiter ? 'waiter' : 'admin'),
        ]);

        // Cache lightweight configuration for 10 minutes, full config for 5 minutes
        $cacheTTL = $lightweight ? now()->addMinutes(10) : now()->addMinutes(5);

        $data = $this->cacheWithTags(['pos-configuration', 'branches', 'registers'])
            ->remember(
                $cacheKey,
                $cacheTTL,
                function () use ($branchId, $lightweight, $user, $isWaiter, $assignedOrderTypes) {
                    $branch = null;
                    $cartIsEmpty = false;

                    try {
                        $cartIsEmpty = Cart::instance()->isEmpty();
                    } catch (\Exception $e) {
                        // Log error but continue with default behavior
                        \Log::warning('Cart instance check failed in getConfiguration', [
                            'error' => $e->getMessage(),
                            'user_id' => $user->id,
                        ]);
                        $cartIsEmpty = true;
                    }

                    $data = [
                        "branches" => [],
                        "menus" => [],
                        "registers" => [],
                        "order_types" => [],
                        "discounts" => [],
                        "branch_id" => $branchId,
                        "menu_id" => null,
                        "register_id" => null,
                        "session_id" => null,
                        "currency" => setting("default_currency"),
                        "directions" => [],
                        "reasons" => [],
                        "action_policy" => $this->posOperationPolicy($user),
                    ];

                    if (! $lightweight) {
                        $data["customers"] = User::list(defaultRole: DefaultRole::Customer);
                    }

                    if (! $lightweight && $user->can('admin.pos_cash_movements.create')) {
                        $directions = PosCashDirection::toArrayTrans([PosCashDirection::Adjust->value]);
                        $reasons = [];

                        foreach ($directions as $direction) {
                            $reasons[$direction['id']] = array_map(
                                fn(PosCashReason $reason) => $reason->toTrans(),
                                PosCashReason::getForManageCashMovement(PosCashDirection::from($direction['id']))
                            );
                        }

                        $data["directions"] = $directions;
                        $data["reasons"] = $reasons;
                    }

                    if (is_null($data['branch_id'])) {
                        if ($user->assignedToBranch()) {
                            $data['branches'][] = [
                                "id" => $user->branch->id,
                                "name" => $user->branch->name,
                                "currency" => $user->branch->currency,
                            ];
                            $branch = $user->branch;
                            $data["branch_id"] = $user->branch->id;
                        } else {
                            $data['branches'] = Branch::list();
                        }
                    }

                    if (count($data['branches']) > 0 || !is_null($data['branch_id'])) {
                        if (is_null($data['branch_id'])) {
                            $data['branch_id'] = $data['branches'][0]['id'];
                        }

                        $branch = $branch ?? Branch::select(
                            'id',
                            'name',
                            'order_types',
                            'currency')
                            ->findOrFail($data['branch_id']);

                        $data['currency'] = $branch->currency;

                        try {
                            if ($cartIsEmpty || ! Cart::instance()->hasBranch()) {
                                Cart::addBranch($branch);
                            }
                        } catch (\Exception $e) {
                            // Log error but continue
                            \Log::warning('Failed to add branch to cart', [
                                'error' => $e->getMessage(),
                                'branch_id' => $branch->id,
                                'user_id' => $user->id,
                            ]);
                        }

                        $orderTypes = $this->allowedOrderTypesForUser(
                            $branch->order_types ?: [],
                            $user,
                            $assignedOrderTypes,
                        );

                        try {
                            if (count($orderTypes) > 0 && ($cartIsEmpty || ! Cart::instance()->hasOrderType())) {
                                Cart::addOrderType(OrderType::from($orderTypes[0]));
                            }
                        } catch (\Exception $e) {
                            // Log error but continue
                            \Log::warning('Failed to add order type to cart', [
                                'error' => $e->getMessage(),
                                'order_type' => $orderTypes[0] ?? null,
                                'user_id' => $user->id,
                            ]);
                        }

                        $data["waiters"] = $lightweight && $isWaiter
                            ? collect([['id' => $user->id, 'name' => $user->name]])
                            : User::list($data['branch_id'], DefaultRole::Waiter);

                        if (! $lightweight) {
                            $data["discounts"] = Discount::list($data['branch_id']);
                        }

                        $data['order_types'] = array_values(array_filter(
                            OrderType::toArrayTrans(),
                            fn($orderType) => in_array($orderType['id'], $orderTypes)
                        ));

                        try {
                            $selectedOrderType = Cart::instance()->hasOrderType()
                                ? Cart::instance()->orderType()->value()
                                : ($orderTypes[0] ?? null);
                        } catch (\Exception $e) {
                            // Log error and use default
                            \Log::warning('Failed to get order type from cart', [
                                'error' => $e->getMessage(),
                                'user_id' => $user->id,
                            ]);
                            $selectedOrderType = $orderTypes[0] ?? null;
                        }

                        $data['menus'] = Menu::list($data['branch_id']);
                        $matchingMenus = $this->filterMenusForOrderType($data['menus'], $selectedOrderType);
                        $data['menu_id'] = $matchingMenus->first()['id'] ?? null;

                        $data['registers'] = PosRegister::list($data['branch_id'], true);
                        $activeSession = PosSession::query()
                            ->withOutGlobalBranchPermission()
                            ->where('branch_id', $data['branch_id'])
                            ->whereIn('pos_register_id', $data['registers']->pluck('id'))
                            ->where('status', PosSessionStatus::Open)
                            ->latest('opened_at')
                            ->first(['id', 'pos_register_id']);
                        $activeRegister = $activeSession
                            ? $data['registers']->first(fn(array $register) => (int) $register['id'] === (int) $activeSession->pos_register_id)
                            : $data['registers']->first(fn(array $register) => !empty($register['session']['id']));
                        $selectedRegister = $activeRegister ?? $data['registers']->first();

                        $data['register_id'] = $selectedRegister['id'] ?? null;
                        $data['session_id'] = $activeSession?->id ?? $selectedRegister['session']['id'] ?? null;
                    }

                    return $data;
                }
            );

        // Register sessions are operational state and can close while the
        // surrounding configuration remains cached. Always overlay them from
        // the database so order payloads never receive a stale session ID.
        return $this->withCurrentRegisterSession($data);
    }

    private function withCurrentRegisterSession(array $data): array
    {
        $branchId = $data['branch_id'] ?? null;
        if (! $branchId) {
            return $data;
        }

        $registers = PosRegister::list((int) $branchId, true);
        $activeSession = PosSession::query()
            ->withOutGlobalBranchPermission()
            ->where('branch_id', $branchId)
            ->whereIn('pos_register_id', $registers->pluck('id'))
            ->where('status', PosSessionStatus::Open->value)
            ->latest('opened_at')
            ->first(['id', 'pos_register_id']);
        $activeRegister = $activeSession
            ? $registers->first(
                fn(array $register) => (int) $register['id'] === (int) $activeSession->pos_register_id
            )
            : $registers->first(fn(array $register) => ! empty($register['session']['id']));
        $selectedRegister = $activeRegister ?? $registers->first();

        $data['registers'] = $registers;
        $data['register_id'] = $selectedRegister['id'] ?? null;
        $data['session_id'] = $activeSession?->id ?? $selectedRegister['session']['id'] ?? null;

        return $data;
    }

    private function allowedOrderTypesForUser(
        array $branchOrderTypes,
        User $user,
        ?array $assignedOrderTypes = null,
    ): array {
        $branchOrderTypes = array_values(array_filter($branchOrderTypes));

        if (! $user->hasRole(DefaultRole::Waiter->value)) {
            return $branchOrderTypes;
        }

        $assignedOrderTypes ??= $this->assignedOrderTypesFor($user);
        if (empty($assignedOrderTypes)) {
            return $branchOrderTypes;
        }

        return array_values(array_intersect($branchOrderTypes, $assignedOrderTypes));
    }

    private function assignedOrderTypesFor(User $user): array
    {
        $orderTypes = null;

        if (array_key_exists('order_types', $user->getAttributes())) {
            $orderTypes = $user->order_types;
        } else {
            $orderTypes = DB::table('users')
                ->where('id', $user->getKey())
                ->value('order_types');
        }

        if (is_string($orderTypes)) {
            $orderTypes = json_decode($orderTypes, true);
        }

        if (! is_array($orderTypes)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn($orderType) => $orderType instanceof OrderType
                ? $orderType->value
                : (is_scalar($orderType) ? (string) $orderType : null),
            $orderTypes,
        )));
    }

    private function posOperationPolicy(User $user): array
    {
        return [
            'create_order' => $this->assistantPolicy($user->can('admin.orders.create'), 'Permission denied.', loadingKey: 'create_order', permissions: ['admin.orders.create']),
            'hold_order' => $this->assistantPolicy($user->can('admin.orders.create'), 'Permission denied.', loadingKey: 'hold_order', permissions: ['admin.orders.create']),
            'resume_order' => $this->assistantPolicy($user->can('admin.orders.edit'), 'Permission denied.', loadingKey: 'resume_order', permissions: ['admin.orders.edit']),
            'receive_payment' => $this->assistantPolicy($user->can('admin.orders.receive_payment'), 'Permission denied.', loadingKey: 'receive_payment', permissions: ['admin.orders.receive_payment']),
            'print_order' => $this->assistantPolicy($user->can('admin.orders.print'), 'Permission denied.', loadingKey: 'print_order', permissions: ['admin.orders.print']),
            'table_transfer' => $this->assistantPolicy($user->can('admin.tables.transfer'), 'Permission denied.', loadingKey: 'table_transfer', permissions: ['admin.tables.transfer'], confirmationRequired: true),
            'table_merge' => $this->assistantPolicy($user->can('admin.tables.merge'), 'Permission denied.', loadingKey: 'table_merge', permissions: ['admin.tables.merge'], confirmationRequired: true),
            'table_split' => $this->assistantPolicy($user->can('admin.tables.split'), 'Permission denied.', loadingKey: 'table_split', permissions: ['admin.tables.split'], confirmationRequired: true),
            'mark_available' => $this->assistantPolicy($user->can('admin.tables.update_status'), 'Permission denied.', loadingKey: 'mark_available', permissions: ['admin.tables.update_status']),
            'cash_movement' => $this->assistantPolicy($user->can('admin.pos_cash_movements.create'), 'Permission denied.', loadingKey: 'cash_movement', permissions: ['admin.pos_cash_movements.create']),
            'waiter_settlement' => $this->assistantPolicy($user->can('admin.waiter_settlements.create'), 'Permission denied.', loadingKey: 'waiter_settlement', permissions: ['admin.waiter_settlements.create']),
        ];
    }
}
