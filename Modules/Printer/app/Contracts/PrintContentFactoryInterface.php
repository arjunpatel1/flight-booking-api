<?php

namespace Modules\Printer\Contracts;

use Modules\Order\Models\Order;
use Modules\Printer\Models\Printer;

interface PrintContentFactoryInterface
{
    /**
     * Get order relations
     *
     * @return array
     */
    public function relations(): array;

    /**
     * Parse order data
     *
     * @param Order $order
     * @return array
     */
    public function resource(Order $order): array;

    /**
     * Get printers
     *
     * @param int|array $specificIds
     * @return array|Printer|null
     */
    public function printers(int|array $specificIds): array|Printer|null;
}
