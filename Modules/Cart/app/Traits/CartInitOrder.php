<?php

namespace Modules\Cart\Traits;

use Darryldecode\Cart\Exceptions\InvalidConditionException;
use Darryldecode\Cart\Exceptions\InvalidItemException;
use Modules\Cart\CartCondition;
use Modules\Cart\CartOrder;
use Modules\Order\Enums\OrderProductAction;
use Modules\Order\Enums\OrderProductStatus;
use Modules\Order\Models\Order;

trait CartInitOrder
{
    /**
     * @throws InvalidConditionException
     * @throws InvalidItemException
     */
    public function initOrder(Order $order): void
    {
        $this->clear();
        $this->addOrder($order);
        $this->addOrderType($order->type);

        foreach ($order->products as $product) {
            $options = [];
            foreach ($product->options as $option) {
                $options[$option->option_id] = $option->option->type->isFieldType()
                    ? $option->value
                    : $option->values->pluck('id')->toArray();

            }

            if (!in_array($product->status, [OrderProductStatus::Cancelled, OrderProductStatus::Refunded])) {
                $this->store($product->product->id, $product->quantity, $options, $product, $product->gift, seatNumber: $product->seat_number);
            }
        }

        if (!is_null($order->customer) && $order->customer?->id) {
            $this->addCustomer($order->customer);
        }

        if ($order->hasDiscount()) {
            $this->applyDiscount($order->discount->discountable, $order->discount->gift);
        }
    }

    /**
     * @throws InvalidConditionException
     */
    public function addOrder(Order $order): CartOrder
    {
        $this->removeOrder();

        $this->condition(
            new CartCondition([
                'name' => $order->reference_no,
                'type' => 'order',
                'value' => $order->id,
            ]),
        );

        $this->addBranch($order->branch);

        return $this->order();
    }

    public function removeOrder(): void
    {
        $this->removeConditionsByType('order');
        $this->flushComputedCache();
    }

    public function order(): ?CartOrder
    {
        return $this->hasOrder()
            ? new CartOrder($this->getConditionsByType('order')->first())
            : null;
    }

    public function hasOrder(): bool
    {
        return $this->getConditionsByType('order')->isNotEmpty();
    }

    public function storeAction(string $id, string $action, int $quantity): void
    {
        $item = $this->get($id);
        $actions = $item->attributes->actions ?? [];
        $status = [];

        switch ($action) {
            case OrderProductAction::Cancel->value:
                $status[] = OrderProductStatus::Preparing;
                break;
            case OrderProductAction::Refund->value:
                $status = [OrderProductStatus::Ready, OrderProductStatus::Served];
                break;
            default:
                return;
        }

        if (in_array($item->attributes->order_product['status'], $status) && $quantity <= $item->quantity) {

            $actions[$action] = [
                'id' => $action,
                'quantity' => $quantity + (isset($actions[$action]) ? $actions[$action]['quantity'] : 0)
            ];

            $this->update($id, [
                'attributes' => [...$item->attributes, "actions" => $actions],
                'quantity' => [
                    'relative' => false,
                    'value' => $item->quantity - $quantity,
                ],
            ]);

            $this->flushComputedCache();
        }
    }

    public function deleteAction(string $id, string $action): void
    {
        $item = $this->get($id);
        $actions = $item->attributes->actions ?? [];
        $quantity = 0;

        if (isset($actions[$action])) {
            $quantity = $actions[$action]['quantity'];
            unset($actions[$action]);
        }

        $this->update($id, [
            'attributes' => [...$item->attributes, "actions" => $actions],
            'quantity' => [
                'relative' => false,
                'value' => $item->quantity + $quantity,
            ],
        ]);

        $this->flushComputedCache();
    }

}
