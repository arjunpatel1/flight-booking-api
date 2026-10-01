<?php

namespace Modules\Order\Services\OrderCreate;

use Modules\Order\Models\Order;
use Modules\Pos\Models\PosSession;
use Modules\User\Models\User;
use Throwable;

interface CreateOrderServiceInterface
{
    /**
     * Store a newly created resource in storage.
     *
     * @throws Throwable
     */
    public function create(array $data): Order;

    /** Create through the normal order pipeline for a trusted non-HTTP actor. */
    public function createForActor(array $data, User $user, bool $trustedServerCharges = false): Order;

    /**
     * Store order payments
     *
     * @throws Throwable
     */
    public function storePayments(
        User $user,
        Order $order,
        ?PosSession $posSession,
        array $paymentMethods,
        array $payments,
    ): void;
}
