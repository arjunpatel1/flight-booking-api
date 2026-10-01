<?php

namespace Modules\Order\Services\OrderPayment;

use Throwable;

interface OrderPaymentServiceInterface
{
    /**
     * Get add payment meta
     *
     * @param int|string $id
     * @return array
     */
    public function getPaymentMeta(int|string $id): array;

    /**
     * Add payment
     *
     * @param int|string $id
     * @param array $data
     * @return void
     * @throws Throwable
     */
    public function storePayment(string|int $id, array $data): void;
}
