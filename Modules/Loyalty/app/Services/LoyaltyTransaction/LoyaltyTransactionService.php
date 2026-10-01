<?php

namespace Modules\Loyalty\Services\LoyaltyTransaction;

use App\NexDine;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Modules\Loyalty\Enums\LoyaltyTransactionType;
use Modules\Loyalty\Models\LoyaltyTransaction;
use Modules\Support\GlobalStructureFilters;
use Modules\User\Enums\DefaultRole;
use Modules\User\Models\User;

class LoyaltyTransactionService implements LoyaltyTransactionServiceInterface
{
    /** @inheritDoc */
    public function label(): string
    {
        return __("loyalty::loyalty_transactions.loyalty_transaction");
    }

    /** @inheritDoc */
    public function get(array $filters = [], ?array $sorts = []): LengthAwarePaginator
    {
        $tenantId = app(\Modules\Saas\Support\TenantContext::class)->id();
        return $this->getModel()
            ->query()
            ->whereHas("customer.customer")
            ->when($tenantId, fn ($query) => $query->whereHas('customer.customer', fn ($customer) => $customer->where('tenant_id', $tenantId)))
            ->with(["customer" => fn($query) => $query->with("customer:id,name")])
            ->filters($filters)
            ->sortBy($sorts)
            ->paginate(NexDine::paginate())
            ->withQueryString();
    }

    /** @inheritDoc */
    public function getModel(): LoyaltyTransaction
    {
        return new ($this->model());
    }

    /** @inheritDoc */
    public function model(): string
    {
        return LoyaltyTransaction::class;
    }

    /** @inheritDoc */
    public function show(int $id): LoyaltyTransaction
    {
        return $this->findOrFail($id);
    }

    /** @inheritDoc */
    public function findOrFail(int $id): Builder|array|EloquentCollection|LoyaltyTransaction
    {
        $tenantId = app(\Modules\Saas\Support\TenantContext::class)->id();
        return $this->getModel()
            ->query()
            ->when($tenantId, fn ($query) => $query->whereHas('customer.customer', fn ($customer) => $customer->where('tenant_id', $tenantId)))
            ->findOrFail($id);
    }

    /** @inheritDoc */
    public function getStructureFilters(): array
    {
        return [
            [
                "key" => 'customer_id',
                "label" => __('loyalty::loyalty_transactions.filters.customer'),
                "type" => 'select',
                "options" => User::list(defaultRole: DefaultRole::Customer),
            ],
            [
                "key" => 'type',
                "label" => __('loyalty::loyalty_transactions.filters.type'),
                "type" => 'select',
                "options" => LoyaltyTransactionType::toArrayTrans(),
            ],
            GlobalStructureFilters::from(),
            GlobalStructureFilters::to(),
        ];
    }
}
