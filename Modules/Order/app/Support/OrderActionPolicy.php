<?php

namespace Modules\Order\Support;

use Modules\Order\Enums\OrderStatus;
use Modules\Order\Enums\OrderType;
use Modules\Order\Models\Order;
use Modules\Order\Services\OrderPayment\OrderPaymentAuthorization;
use Modules\Support\ActionPolicy;
use Modules\User\Models\User;

class OrderActionPolicy
{
    public static function make(Order $order, ?User $user): array
    {
        $policy = new self($order, $user);

        return [
            'view' => $policy->forAction(true, null, ['admin.orders.show', 'admin.orders.index', 'admin.orders.active'], 'order_view'),
            'edit' => $policy->forAction(
                $order->editIsAllowed(),
                __('order::messages.edit_not_allowed'),
                ['admin.orders.edit'],
                'order_edit',
            ),
            'print' => $policy->forAction(true, null, ['admin.orders.print'], 'order_print'),
            'cancel' => $policy->forAction(
                $order->cancelIsAllowed(),
                __('order::messages.order_cannot_cancel', ['status' => $order->status->trans()]),
                ['admin.orders.cancel'],
                'order_cancel',
                managerApprovalRequired: true,
                confirmationRequired: true,
            ),
            'refund' => $policy->forAction(
                $order->refundIsAllowed(),
                __('order::messages.order_cannot_refund'),
                ['admin.orders.refund'],
                'order_refund',
                managerApprovalRequired: true,
                confirmationRequired: true,
            ),
            'receive_payment' => $policy->payment(),
            'update_status' => $policy->forAction(
                $order->allowUpdateStatus(),
                __('order::messages.could_not_update_order_status'),
                ['admin.orders.update_status'],
                'order_update_status',
                meta: [
                    'next_status' => $order->next_status?->toTrans(),
                ],
            ),
        ];
    }

    private function __construct(
        private readonly Order $order,
        private readonly ?User $user,
    ) {
    }

    private function payment(): array
    {
        if (! $this->order->allowAddPayment()) {
            return $this->forAction(false, __('order::messages.order_payment_not_allowed'), ['admin.orders.receive_payment'], 'receive_payment');
        }

        if (! app(OrderPaymentAuthorization::class)->canReceivePayment($this->order, $this->user)) {
            return $this->forAction(false, __('order::messages.order_payment_owner_not_allowed'), ['admin.orders.receive_payment'], 'receive_payment');
        }

        if (! $this->paymentAllowedByStatusFlow()) {
            return $this->forAction(false, __('order::messages.order_payment_waiting_for_service'), ['admin.orders.receive_payment'], 'receive_payment');
        }

        return $this->forAction(true, null, ['admin.orders.receive_payment'], 'receive_payment');
    }

    private function paymentAllowedByStatusFlow(): bool
    {
        return $this->order->type !== OrderType::DineIn
            || ! (bool) setting('waiter_table_status_flow_enabled', true)
            || $this->order->next_status === OrderStatus::Completed;
    }

    private function forAction(
        bool $businessAllowed,
        ?string $businessReason,
        array $permissions,
        string $loadingKey,
        bool $managerApprovalRequired = false,
        bool $confirmationRequired = false,
        array $meta = [],
    ): array {
        $permissionAllowed = $this->canAny($permissions);
        $allowed = $businessAllowed && $permissionAllowed;
        $reason = match (true) {
            ! $permissionAllowed => 'Permission denied.',
            ! $businessAllowed => $businessReason,
            default => null,
        };

        return ActionPolicy::make(
            allowed: $allowed,
            reason: $reason,
            visible: $permissionAllowed,
            managerApprovalRequired: $managerApprovalRequired,
            loadingKey: $loadingKey,
            permissions: $permissions,
            confirmationRequired: $confirmationRequired,
            meta: $meta,
        );
    }

    private function canAny(array $permissions): bool
    {
        if (empty($permissions)) {
            return true;
        }

        return $this->user !== null && collect($permissions)->contains(
            fn(string $permission) => $this->user->can($permission)
        );
    }
}
