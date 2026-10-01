<?php

namespace Modules\Pos\Services\KitchenViewer;

use Throwable;

interface KitchenViewerServiceInterface
{
    /**
     * Get configuration
     *
     * @param int|null $branchId
     * @return array
     */
    public function getConfiguration(?int $branchId = null): array;

    /**
     * Get orders
     *
     * @param int|null $branchId
     * @return array
     */
    public function getOrders(?int $branchId = null): array;

    /**
     * Move order products to next status
     *
     * @param int|string $orderId
     * @param array|int $ids
     * @return void
     * @throws Throwable
     */
    public function moveOrderProductToNextStatus(int|string $orderId, array|int $ids): void;

    /**
     * Cancel selected kitchen order products and notify the assigned waiter.
     *
     * @param int|string $orderId
     * @param array|int $ids
     * @param string $reason
     * @return void
     * @throws Throwable
     */
    public function cancelOrderProducts(int|string $orderId, array|int $ids, string $reason): void;

    /**
     * Cancel a delayed kitchen order and notify the assigned waiter.
     *
     * @param int|string $orderId
     * @param string $reason
     * @return void
     * @throws Throwable
     */
    public function cancelDelayedOrder(int|string $orderId, string $reason): void;
}
