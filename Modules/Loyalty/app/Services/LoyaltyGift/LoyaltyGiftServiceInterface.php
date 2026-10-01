<?php

namespace Modules\Loyalty\Services\LoyaltyGift;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Modules\Loyalty\Models\LoyaltyCustomer;
use Modules\Loyalty\Models\LoyaltyGift;
use Modules\Order\Models\Order;
use Throwable;

interface LoyaltyGiftServiceInterface
{
    /**
     * Redeem points immediately to create a persistent gift.
     * No order is required.
     *
     * @param int $rewardId
     * @param int $customerId
     * @param array $ctx
     * @return LoyaltyGift
     * @throws Throwable
     */
    public function redeem(int $rewardId, int $customerId, array $ctx = []): LoyaltyGift;

    /**
     * Get active gifts for a customer.
     *
     * @param int $customerId
     * @param int|null $programId
     * @return Collection
     */
    public function availableGifts(int $customerId, ?int $programId = null): Collection;

    /**
     * Use a gift on an order.
     *
     * @param int $loyaltyGiftId
     * @param Order|null $order
     * @param LoyaltyCustomer|null $lc
     * @throws Throwable
     */
    public function useGift(int $loyaltyGiftId, ?Order $order = null): void;

    /**
     * Expire old gifts.
     *
     * @param int $batch
     * @throws Throwable
     */
    public function expireGifts(int $batch = 100): int;

    /**
     * Fetch all rewards currently available for redemption by the given customer.
     *
     * @param int $customerId
     * @param int|null $programId
     * @param int|null $branchId
     * @return array
     */
    public function getRewards(int $customerId, ?int $programId = null, ?int $branchId = null): array;

    /**
     * Get  gift for a customer by id.
     *
     * @param int $customerId
     * @param int $giftId
     * @param int|null $branchId
     * @return LoyaltyGift|null
     */
    public function availableGift(int $customerId, int $giftId, ?int $branchId = null): ?LoyaltyGift;

    /**
     * Rollback Gift on an order.
     *
     * @param int $loyaltyGiftId
     * @param Order $order
     * @return void
     * @throws Throwable
     */
    public function rollbackGift(int $loyaltyGiftId, Order $order): void;

    /**
     * Display a listing of the resource.
     *
     * @param array $filters
     * @param array $sorts
     * @return LengthAwarePaginator
     */
    public function get(array $filters = [], array $sorts = []): LengthAwarePaginator;

    /**
     * Get structure filters for frontend
     *
     * @param int|null $programId
     * @return array
     */
    public function getStructureFilters(?int $programId = null): array;

    /**
     * Model for the resource.
     *
     * @return string
     */
    public function model(): string;

    /**
     * Get a new instance of the model.
     *
     * @return LoyaltyGift
     */
    public function getModel(): LoyaltyGift;
}
