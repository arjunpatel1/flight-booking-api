<?php

namespace Modules\Loyalty\Services\Loyalty;

use Modules\Loyalty\Models\LoyaltyCustomer;
use Modules\Loyalty\Models\LoyaltyProgram;
use Modules\Loyalty\Models\LoyaltyTransaction;
use Modules\Order\Models\Order;
use Throwable;

interface LoyaltyServiceInterface
{
    /**
     * Manually credit (positive) or debit (negative) points for a loyalty customer
     * — a staff/CRM goodwill or correction adjustment. Records an `adjust`
     * transaction and updates the balance. A debit cannot push the balance negative.
     *
     * @throws Throwable
     */
    public function adjustPoints(LoyaltyCustomer $customer, int $points, ?string $reason = null, ?int $userId = null): LoyaltyTransaction;

    /**
     * Award points for a completed order. Idempotent per (order_id, type=earn).
     *
     * @param Order $order
     * @param array{program_id:int|null,promotions:array} $context [
     * @return array{points:int, bonus:int, total:int, customer_id:int, tier_id:int|null}|null
     * @throws Throwable
     */
    public function earnForOrder(Order $order, array $context = []): ?array;

    /**
     * Recalculate and update the customer's tier; returns the (possibly new) tier ID.
     *
     * @param LoyaltyCustomer $lc
     * @param LoyaltyProgram $program
     * @return int|null
     */
    public function recomputeTier(LoyaltyCustomer $lc, LoyaltyProgram $program): ?int;

    /**
     * Reverse points when an order is canceled or returned.
     *
     * @param Order $order
     * @param array{partial_amount:float|null,reason:string|null} $context [
     * @return array{reversed:int, remaining_balance:int}
     * @throws Throwable
     */
    public function cancelForOrder(Order $order, array $context = []): array;

    /**
     * Expire points that passed validity. Intended for a daily scheduler (e.g., cron).
     * This uses FIFO across EARN/BONUS transactions that have a meta.valid_until date in the past.
     *
     * @param int $batch
     * @return int
     * @throws Throwable
     */
    public function expirePoints(int $batch = 100): int;
}
