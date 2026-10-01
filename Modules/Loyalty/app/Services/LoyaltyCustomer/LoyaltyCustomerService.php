<?php

namespace Modules\Loyalty\Services\LoyaltyCustomer;

use App\NexDine;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Modules\Loyalty\Models\LoyaltyCustomer;
use Modules\Loyalty\Models\LoyaltyProgram;
use Modules\Loyalty\Models\LoyaltyTier;
use Modules\Support\GlobalStructureFilters;
use Modules\User\Enums\DefaultRole;
use Modules\User\Models\User;

class LoyaltyCustomerService implements LoyaltyCustomerServiceInterface
{
    /** @inheritDoc */
    public function label(): string
    {
        return __("loyalty::loyalty_customers.loyalty_customer");
    }

    /** @inheritDoc */
    public function get(array $filters = [], ?array $sorts = []): LengthAwarePaginator
    {
        $tenantId = app(\Modules\Saas\Support\TenantContext::class)->id();
        return $this->getModel()
            ->query()
            ->whereHas("customer")
            ->when($tenantId, fn ($query) => $query->whereHas('customer', fn ($customer) => $customer->where('tenant_id', $tenantId)))
            ->with([
                "customer:id,name",
                "loyaltyProgram:id,name",
                "loyaltyTier:id,name"
            ])
            ->filters($filters)
            ->sortBy($sorts)
            ->paginate(NexDine::paginate())
            ->withQueryString();
    }

    /** @inheritDoc */
    public function getModel(): LoyaltyCustomer
    {
        return new ($this->model());
    }

    /** @inheritDoc */
    public function model(): string
    {
        return LoyaltyCustomer::class;
    }

    /** @inheritDoc */
    public function show(int $id): LoyaltyCustomer
    {
        return $this->findOrFail($id);
    }

    /** @inheritDoc */
    public function findOrFail(int $id): Builder|array|EloquentCollection|LoyaltyCustomer
    {
        $tenantId = app(\Modules\Saas\Support\TenantContext::class)->id();
        return $this->getModel()
            ->query()
            ->when($tenantId, fn ($query) => $query->whereHas('customer', fn ($customer) => $customer->where('tenant_id', $tenantId)))
            ->findOrFail($id);
    }

    /** @inheritDoc */
    public function getStructureFilters(?int $programId = null): array
    {
        return [
            [
                "key" => 'customer_id',
                "label" => __('loyalty::loyalty_customers.filters.customer'),
                "type" => 'select',
                "options" => User::list(defaultRole: DefaultRole::Customer),
            ],
            [
                "key" => 'loyalty_program_id',
                "label" => __('loyalty::loyalty_customers.filters.loyalty_program'),
                "type" => 'select',
                "options" => LoyaltyProgram::list(),
            ],
            [
                "key" => 'loyalty_tier_id',
                "label" => __('loyalty::loyalty_customers.filters.loyalty_tier'),
                "type" => 'select',
                "options" => !is_null($programId) ? LoyaltyTier::list($programId) : [],
                "depends" => "loyalty_program_id"
            ],
            GlobalStructureFilters::from(),
            GlobalStructureFilters::to(),
        ];
    }

}
