<?php

namespace Modules\Printer\Factories\PrintContents;

use Modules\Order\Models\Order;
use Modules\Order\Models\OrderProduct;
use Modules\Order\Models\OrderTax;
use Modules\Payment\Models\Payment;
use Modules\Pos\Models\PosRegister;
use Modules\Printer\app\Factories\OrderResourceFactory;
use Modules\Printer\Contracts\PrintContentFactoryInterface;
use Modules\Printer\Models\Printer;

class PrintDeliveryContentFactory implements PrintContentFactoryInterface
{

    /** @inheritDoc */
    public function relations(): array
    {
        return [
            "branch",
            "customer",
            "products",
            "taxes",
            "payments",
            "discount",
        ];
    }

    /** @inheritDoc */
    public function resource(Order $order): array
    {
        return [
            "fulfilment" => $order->fulfilmentDetails(),
            "order" => OrderResourceFactory::order($order),
            "branch" => OrderResourceFactory::branch($order->branch),
            "customer" => OrderResourceFactory::customer($order),
            "discount" => !is_null($order->discount) ? OrderResourceFactory::discount($order->discount) : null,
            "payments" => $order->payments->map(fn(Payment $p) => OrderResourceFactory::payment($p)),
            "taxes" => $order->taxes->map(fn(OrderTax $orderTax) => OrderResourceFactory::tax($orderTax)),
            "products" => $order->products->map(fn(OrderProduct $product) => OrderResourceFactory::product($product)),
        ];
    }

    /** @inheritDoc */
    public function printers(int|array $specificIds): array|Printer|null
    {
        $register = PosRegister::query()
            ->with("deliveryPrinter")
            ->where('id', $specificIds)
            ->first();

        return $register?->deliveryPrinter;
    }
}
