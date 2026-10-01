<?php

namespace Modules\Report\Services\WaiterSettlement;

use App\NexDine;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Payment\Enums\PaymentMethod;
use Modules\Payment\Enums\PaymentType;
use Modules\Report\Models\WaiterCollectionSettlement;
use Modules\Support\Money;

class WaiterSettlementService implements WaiterSettlementServiceInterface
{
    /** @inheritDoc */
    public function label(): string
    {
        return __("report::waiter_settlements.settlement");
    }

    /** @inheritDoc */
    public function get(array $filters = [], array $sorts = []): LengthAwarePaginator
    {
        return WaiterCollectionSettlement::query()
            ->with(["waiter:id,name", "settledBy:id,name"])
            ->when($this->branchId(), fn(Builder $q, $id) => $q->where('branch_id', $id))
            ->latest('business_date')
            ->paginate(NexDine::paginate())
            ->withQueryString();
    }

    /** @inheritDoc */
    public function preview(int $waiterId, string $businessDate): array
    {
        $branchId = $this->branchId();
        $expected = $this->expectedCash($waiterId, $businessDate, $branchId);

        $settlement = WaiterCollectionSettlement::query()
            ->with(["waiter:id,name", "settledBy:id,name"])
            ->where('waiter_id', $waiterId)
            ->where('business_date', $businessDate)
            ->when($branchId, fn(Builder $q, $id) => $q->where('branch_id', $id))
            ->first();

        return [
            'waiter_id' => $waiterId,
            'business_date' => $businessDate,
            'expected_amount' => $expected,
            'currency' => auth()->user()?->branch?->currency,
            'settlement' => $settlement,
        ];
    }

    /** @inheritDoc */
    public function store(array $data): WaiterCollectionSettlement
    {
        $branchId = $this->branchId();
        $waiterId = (int) $data['waiter_id'];
        $businessDate = $data['business_date'];
        $settled = (float) $data['settled_amount'];

        $expected = $this->expectedCash($waiterId, $businessDate, $branchId);

        return WaiterCollectionSettlement::query()->updateOrCreate(
            [
                'waiter_id' => $waiterId,
                'business_date' => $businessDate,
                'branch_id' => $branchId,
            ],
            [
                'currency' => auth()->user()?->branch?->currency ?? Money::defaultCurrency(),
                'expected_amount' => $expected,
                'settled_amount' => $settled,
                'difference_amount' => $settled - $expected,
                'status' => 'settled',
                'settled_by' => auth()->id(),
                'settled_at' => now(),
                'notes' => $data['notes'] ?? null,
            ]
        );
    }

    /**
     * Cash collected by the waiter for the business date (live, not the
     * job-built fact table) — sum of cash payments on that waiter's orders.
     */
    private function expectedCash(int $waiterId, string $businessDate, ?int $branchId): float
    {
        return (float) DB::table('payments')
            ->join('orders', 'orders.id', '=', 'payments.order_id')
            ->whereDate('orders.order_date', $businessDate)
            ->where('orders.waiter_id', $waiterId)
            ->when($branchId, fn($q, $id) => $q->where('orders.branch_id', $id))
            ->whereNull('payments.deleted_at')
            ->where('payments.type', PaymentType::Payment->value)
            ->where('payments.method', PaymentMethod::Cash->value)
            ->sum('payments.amount');
    }

    /**
     * The authenticated user's branch id, or null for tenant/admin-wide access.
     */
    private function branchId(): ?int
    {
        $user = auth()->user();
        return $user?->assignedToBranch() ? $user->branch_id : null;
    }
}
