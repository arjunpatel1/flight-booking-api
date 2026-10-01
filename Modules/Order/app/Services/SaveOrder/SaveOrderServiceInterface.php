<?php

namespace Modules\Order\Services\SaveOrder;

use Modules\Order\Models\Order;
use Throwable;

interface SaveOrderServiceInterface
{
    /**
     * Store a newly created resource in storage.
     *
     * @param array $data
     * @return Order
     * @throws Throwable
     */
    public function create(array $data): Order;

    /**
     * Update the specified translation in storage.
     *
     * @param int $id
     * @param array $data
     * @return Order
     * @throws Throwable
     */
    public function update(int $id, array $data): Order;
}
