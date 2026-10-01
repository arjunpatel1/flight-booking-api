<?php

namespace Modules\Payment\Services\Payment;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Modules\Order\Models\Order;
use Modules\Payment\Models\Payment;

interface PaymentServiceInterface
{
    /**
     * Label for the resource.
     *
     * @return string
     */
    public function label(): string;

    /**
     * Model for the resource.
     *
     * @return string
     */
    public function model(): string;

    /**
     * Get a new instance of the model.
     *
     * @return Payment
     */
    public function getModel(): Payment;

    /**
     * Display a listing of the resource.
     *
     * @param array $filters
     * @param array $sorts
     * @return LengthAwarePaginator
     */
    public function get(array $filters = [], array $sorts = []): LengthAwarePaginator;

    /**
     * Show the specified resource.
     *
     * @param int $id
     * @return Payment
     * @throws ModelNotFoundException
     */
    public function show(int $id): Payment;

    /**
     * Get structure filters for frontend
     *
     * @return array
     */
    public function getStructureFilters(): array;

    /**
     * Process a POS-facing payment for an order.
     *
     * @param Order $order
     * @param array $data
     * @return Payment
     */
    public function processPayment(Order $order, array $data): Payment;

    /**
     * Settle the remaining due of an order as a complimentary (no-charge) bill.
     *
     * @param Order $order
     * @param string $reason
     * @return Payment
     */
    public function complimentaryPayment(Order $order, string $reason): Payment;

    /**
     * Refund a payment.
     *
     * @param Payment $payment
     * @param string $reason
     * @return Payment
     */
    public function refundPayment(Payment $payment, string $reason): Payment;
}
