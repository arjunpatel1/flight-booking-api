<?php

namespace Modules\Printer\Services\Dispatcher;


use Modules\Order\Models\Order;
use Modules\Printer\Enum\PrintContentType;
use Throwable;

interface PrintDispatcherServiceInterface
{
    /**
     * Dispatch a generic print job based on content type.
     *
     * This is the core method used internally by all
     * specialized dispatch methods.
     *
     * Behavior:
     * - For Kitchen content:
     *   - May dispatch multiple print jobs (one per kitchen printer)
     * - For non-kitchen content:
     *   - Dispatches a single print job
     *
     * @param Order $order
     * @param PrintContentType $type
     * @param int|null $specificId
     *        Optional target identifier:
     *        - POS register ID for cashier-related prints
     *        - Kitchen user ID for kitchen prints
     *        - Null to auto-resolve targets
     *
     * @param bool $retryDuplicate
     *        When true, an existing duplicate job is reset to pending so
     *        manual reprint actions still reach the local print agent.
     * @param array $options
     *        Optional print context. Currently used for prepared kitchen
     *        delta payloads during order edits.
     *
     * @return void
     * @throws Throwable
     */
    public function dispatch(
        Order $order,
        PrintContentType $type,
        ?int $specificId = null,
        bool $retryDuplicate = false,
        array $options = []
    ): void;

    /**
     * Dispatch a bill print job.
     * Typically used before payment confirmation.
     *
     * @param Order $order
     * @param int|null $registerId
     *        Optional POS register ID to override auto resolution.
     *
     * @return void
     * @throws Throwable
     */
    public function dispatchBill(Order $order, ?int $registerId = null): void;

    /**
     * Dispatch a finalized invoice print job.
     * Usually triggered after successful payment.
     *
     * @param Order $order
     * @param int|null $registerId
     *        Optional POS register ID to override auto resolution.
     *
     * @return void
     * @throws Throwable
     */
    public function dispatchInvoice(Order $order, ?int $registerId = null): void;

    /**
     * Dispatch delivery ticket print job.
     * Used for delivery or takeaway orders.
     *
     * @param Order $order
     * @param int|null $registerId
     *        Optional POS register ID to override auto resolution.
     *
     * @return void
     * @throws Throwable
     */
    public function dispatchDelivery(Order $order, ?int $registerId = null): void;

    /**
     * Dispatch waiter ticket print job.
     *
     * Typically used for internal service flow
     * between kitchen and wait staff.
     *
     * @param Order $order
     * @param int|null $registerId
     *        Optional POS register ID to override auto resolution.
     *
     * @return void
     * @throws Throwable
     */
    public function dispatchWaiter(Order $order, ?int $registerId = null): void;

    /**
     * Dispatch kitchen tickets.
     *
     * This may result in multiple print jobs,
     * one per kitchen printer, depending on
     * category routing rules.
     *
     * @param Order $order
     * @param int|null $kitchenId
     *        Optional specific kitchen user ID.
     *        If null, all eligible kitchens will be resolved.
     *
     * @return void
     * @throws Throwable
     */
    public function dispatchKitchens(Order $order, ?int $kitchenId = null): void;
}
