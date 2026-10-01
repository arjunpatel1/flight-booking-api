<?php

namespace Modules\Order\Services\OrderPayment;

use Illuminate\Support\Collection;
use Modules\Order\Models\Order;
use Modules\User\Enums\DefaultRole;
use Modules\User\Models\User;

class OrderPaymentAuthorization
{
    /**
     * Waiters may settle only orders they own. Elevated POS roles retain the
     * cross-order settlement workflow used by cashiers and managers.
     */
    public function canReceivePayment(Order $order, ?User $user): bool
    {
        if (is_null($user)) {
            return false;
        }

        if (!$user->hasRole(DefaultRole::Waiter->value) || $this->hasElevatedPosRole($user)) {
            return true;
        }

        return (int) $order->waiter_id === (int) $user->id
            || (int) $order->created_by === (int) $user->id;
    }

    public function authorize(Order $order, ?User $user): void
    {
        abort_unless(
            $this->canReceivePayment($order, $user),
            403,
            __('order::messages.order_payment_owner_not_allowed')
        );
    }

    /** @param Collection<int, Order> $orders */
    public function authorizeMany(Collection $orders, ?User $user): void
    {
        abort_unless(
            $orders->every(fn (Order $order) => $this->canReceivePayment($order, $user)),
            403,
            __('order::messages.order_payment_owner_not_allowed')
        );
    }

    private function hasElevatedPosRole(User $user): bool
    {
        return $user->hasAnyRole([
            DefaultRole::SuperAdmin->value,
            DefaultRole::Admin->value,
            DefaultRole::AdminBranch->value,
            DefaultRole::Manager->value,
            DefaultRole::Cashier->value,
        ]);
    }
}
