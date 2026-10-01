<?php

namespace Modules\Order\Services\SplitOrder;

use Modules\Order\Models\Order;

interface SplitOrderServiceInterface
{
    /**
     * Split a bill: move the given line items into a new, separately-payable child
     * order. Totals/taxes are recomputed for both via the order's own recalculate().
     *
     * @param int[] $itemIds order_product ids to move to the new child bill
     * @return array{parent: Order, child: Order}
     */
    public function split(Order $parent, array $itemIds): array;
}
