<?php

namespace Modules\Printer\Factories\PrintContents;

use Modules\Order\Models\Order;
use Modules\Order\Models\OrderProduct;
use Modules\Pos\Models\PosRegister;
use Modules\Printer\app\Factories\OrderResourceFactory;
use Modules\Printer\Contracts\PrintContentFactoryInterface;
use Modules\Printer\Models\Printer;

class PrintWaiterContentFactory implements PrintContentFactoryInterface
{

    /** @inheritDoc */
    public function relations(): array
    {
        return [
            "customer",
            "products.options.values",
            "table:id,name",
            "waiter:id,name",
        ];
    }

    /** @inheritDoc */
    public function resource(Order $order): array
    {
        return [
            "order" => OrderResourceFactory::order($order, true),
            "customer" => OrderResourceFactory::customer($order),
            "table" => !is_null($order->table) ? OrderResourceFactory::table($order->table) : null,
            "waiter" => !is_null($order->waiter) ? OrderResourceFactory::waiter($order->waiter) : null,
            "products" => $order->products->map(fn(OrderProduct $product) => OrderResourceFactory::product($product, true)),
        ];
    }

    /** @inheritDoc */
    public function printers(int|array $specificIds): array|Printer|null
    {
        $register = PosRegister::query()
            ->with("waiterPrinter")
            ->where('id', $specificIds)
            ->first();

        return $register?->waiterPrinter;
    }
}
