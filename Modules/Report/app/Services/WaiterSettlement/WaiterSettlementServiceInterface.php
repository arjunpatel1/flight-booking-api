<?php

namespace Modules\Report\Services\WaiterSettlement;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Report\Models\WaiterCollectionSettlement;

interface WaiterSettlementServiceInterface
{
    public function label(): string;

    /**
     * Paginated list of settlements (branch-scoped).
     */
    public function get(array $filters = [], array $sorts = []): LengthAwarePaginator;

    /**
     * Live preview of the cash a waiter is expected to settle for a date,
     * plus any existing settlement for that waiter/date.
     *
     * @return array{waiter_id:int, business_date:string, expected_amount:float, currency:?string, settlement:?WaiterCollectionSettlement}
     */
    public function preview(int $waiterId, string $businessDate): array;

    /**
     * Record a settlement: computes expected vs settled and the difference.
     */
    public function store(array $data): WaiterCollectionSettlement;
}
