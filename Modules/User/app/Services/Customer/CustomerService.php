<?php

namespace Modules\User\Services\Customer;

use App\NexDine;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderFeedback;
use Modules\Support\Country;
use Modules\Support\GlobalStructureFilters;
use Modules\User\Enums\DefaultRole;
use Modules\User\Enums\GenderType;
use Modules\User\Models\User;

class CustomerService implements CustomerServiceInterface
{
    /** {@inheritDoc} */
    public function label(): string
    {
        return __('user::users.user');
    }

    /** {@inheritDoc} */
    public function show(int $id): User
    {
        return $this->findOrFail($id);
    }

    /** {@inheritDoc} */
    public function findOrFail(int $id): Builder|array|EloquentCollection|User
    {
        return $this->getModel()
            ->query()
            ->role(DefaultRole::Customer)
            ->withoutGlobalActive()
            ->with('branch:id,name')
            ->findOrFail($id);
    }

    /** {@inheritDoc} */
    public function getModel(): User
    {
        return new ($this->model());
    }

    /** {@inheritDoc} */
    public function model(): string
    {
        return User::class;
    }

    /** {@inheritDoc} */
    public function store(array $data): User
    {
        return DB::transaction(function () use ($data) {
            $customer = $this->getModel()->query()->create($data)->assignRole(DefaultRole::Customer);
            $program = \Modules\Loyalty\Models\LoyaltyProgram::currentForTenant($customer->tenant_id);
            if ($program) {
                \Modules\Loyalty\Models\LoyaltyCustomer::query()->firstOrCreate(
                    ['customer_id' => $customer->id, 'loyalty_program_id' => $program->id],
                    ['points_balance' => 0, 'lifetime_points' => 0]
                );
            }

            return $customer;
        });
    }

    /** {@inheritDoc} */
    public function update(int $id, array $data): User
    {
        return DB::transaction(function () use ($id, $data) {
            $user = $this->findOrFail($id);
            $user->update(Arr::except($data, empty($data['password']) ? ['password'] : []));

            // Existing CRM customers may predate the restaurant's loyalty program.
            $program = \Modules\Loyalty\Models\LoyaltyProgram::currentForTenant($user->tenant_id);
            if ($program) {
                \Modules\Loyalty\Models\LoyaltyCustomer::query()->firstOrCreate(
                    ['customer_id' => $user->id, 'loyalty_program_id' => $program->id],
                    ['points_balance' => 0, 'lifetime_points' => 0]
                );
            }

            return $user;
        });
    }

    /** {@inheritDoc} */
    public function destroy(int|array|string $ids): bool
    {
        return DB::transaction(function () use ($ids): bool {
            $query = $this->getModel()
                ->query()
                ->withoutGlobalActive()
                ->role(DefaultRole::Customer)
                ->whereIn('id', parseIds($ids));

            // A deleted customer must not permanently reserve a login name.
            (clone $query)->update(['username' => null]);

            return $query->delete() ?: false;
        });
    }

    /** {@inheritDoc} */
    public function getStructureFilters(): array
    {
        return [
            [
                'key' => 'gender',
                'label' => __('user::customers.filters.gender'),
                'type' => 'select',
                'options' => GenderType::toArrayTrans(),
            ],
            GlobalStructureFilters::active(),
            GlobalStructureFilters::from(),
            GlobalStructureFilters::to(),
        ];
    }

    /** {@inheritDoc} */
    public function getFormMeta(): array
    {
        return [
            'genders' => GenderType::toArrayTrans(),
            'countries' => Country::toList(),
        ];
    }

    /** {@inheritDoc} */
    public function search(?string $query = null): Collection
    {
        if (is_null($query)) {
            return collect();
        }

        return $this->getModel()
            ->query()
            ->role(DefaultRole::Customer)
            ->search($query)
            ->limit(5)
            ->get();
    }

    /** {@inheritDoc} */
    public function get(array $filters = [], ?array $sorts = []): LengthAwarePaginator
    {
        return $this->getModel()
            ->query()
            ->withoutGlobalActive()
            ->role(DefaultRole::Customer)
            ->with('branch:id,name')
            ->withCount([
                'orders as completed_orders_count' => fn ($query) => $query->withoutCanceledOrders(),
                'feedback as feedback_count',
            ])
            ->withAvg('feedback as average_rating', 'rating')
            ->addSelect([
                'total_sales' => Order::query()
                    ->selectRaw('COALESCE(SUM(total * COALESCE(currency_rate, 1)), 0)')
                    ->whereColumn('orders.customer_id', 'users.id')
                    ->withoutCanceledOrders(),
                'last_order_at' => Order::query()
                    ->selectRaw('MAX(created_at)')
                    ->whereColumn('orders.customer_id', 'users.id')
                    ->withoutCanceledOrders(),
                'last_feedback_at' => OrderFeedback::query()
                    ->selectRaw('MAX(submitted_at)')
                    ->whereColumn('order_feedback.customer_id', 'users.id'),
            ])
            ->filters($filters)
            ->sortBy($sorts)
            ->paginate(NexDine::paginate())
            ->withQueryString();
    }

    /** {@inheritDoc} */
    public function quickStore(array $data): User
    {
        $payload = [
            'name' => $data['name'],
            'phone' => $data['phone'] ?? null,
            'phone_country_iso_code' => $data['phone_country_iso_code'] ?? (setting('default_country_iso_code') ?: 'IN'),
            'email' => $data['email'] ?? null,
            'branch_id' => $data['branch_id'] ?? null,
            'is_active' => true,
        ];

        if (! empty($data['username'])) {
            $payload['username'] = $data['username'];
        }

        if (! empty($data['password'])) {
            $payload['password'] = $data['password'];
            $payload['password_confirmation'] = $data['password'];
        }

        return $this->store($payload);
    }

    /** {@inheritDoc} */
    public function showDetail(int $id): array
    {
        $customer = $this->findOrFail($id);

        $customer->load([
            'orders' => fn ($q) => $q->withoutCanceledOrders()->latest()->limit(10),
            'orders.branch:id,name',
            'orders.table:id,name',
            'feedback' => fn ($q) => $q->latest()->limit(10),
            'branch:id,name',
        ]);

        $activeProgramId = \Modules\Loyalty\Models\LoyaltyProgram::currentForTenant($customer->tenant_id)?->id;
        $loyaltyCustomer = \Modules\Loyalty\Models\LoyaltyCustomer::query()
            ->where('customer_id', $id)
            ->with('loyaltyProgram:id,name', 'loyaltyTier:id,name')
            ->when($activeProgramId, fn ($query) => $query->orderByRaw(
                'CASE WHEN loyalty_program_id = ? THEN 0 ELSE 1 END', [$activeProgramId]))
            ->latest('id')
            ->first();

        $giftCards = \Modules\Voucher\Models\GiftCard::query()
            ->where('customer_id', $id)
            ->where('status', 'active')
            ->select(['id', 'code', 'current_balance', 'expires_at'])
            ->get();

        return [
            'customer' => $customer,
            'loyalty' => $loyaltyCustomer,
            'gift_cards' => $giftCards,
            'stats' => [
                'total_orders' => $customer->orders()->withoutCanceledOrders()->count(),
                'total_spent' => $customer->orders()->withoutCanceledOrders()->sum('total'),
                'average_order_value' => $customer->orders()->withoutCanceledOrders()->avg('total'),
                'feedback_count' => $customer->feedback()->count(),
                'average_rating' => $customer->feedback()->avg('rating'),
            ],
        ];
    }

    /** {@inheritDoc} */
    public function engagementSummary(): array
    {
        $inactiveDays = max(1, (int) (setting('crm_inactive_customer_days') ?: 30));
        $recentDays = max(1, (int) (setting('crm_recent_customer_days') ?: 7));
        $highValueMinimumSpend = max(0, (float) (setting('crm_high_value_customer_min_spend') ?: 500));

        // Deactivated customers were removed by the global active scope, so
        // they were missing from every figure here — including the "Inactive
        // customers" card, which could therefore never count the very rows it
        // is named after.
        $customers = $this->getModel()
            ->query()
            ->withoutGlobalActive()
            ->role(DefaultRole::Customer);

        $recentCustomerIds = Order::query()
            ->select('customer_id')
            ->whereNotNull('customer_id')
            ->where('created_at', '>=', now()->subDays($recentDays))
            ->withoutCanceledOrders();

        $highValueCustomerIds = Order::query()
            ->select('customer_id')
            ->whereNotNull('customer_id')
            ->withoutCanceledOrders()
            ->groupBy('customer_id')
            ->havingRaw('SUM(total * COALESCE(currency_rate, 1)) >= ?', [$highValueMinimumSpend]);

        $birthdayCustomers = Schema::hasColumn('users', 'date_of_birth')
            ? (clone $customers)
                ->whereMonth('date_of_birth', now()->month)
                ->whereDay('date_of_birth', now()->day)
                ->count()
            : 0;

        return [
            'total_customers' => (clone $customers)->count(),
            // "Inactive" on the account dashboard means access is disabled.
            // Engagement recency is reported separately below; mixing the two
            // caused active, newly-created customers to be counted inactive.
            'inactive_customers' => (clone $customers)->where('is_active', false)->count(),
            'recent_customers' => (clone $customers)->whereIn('id', $recentCustomerIds)->count(),
            'high_value_customers' => (clone $customers)->whereIn('id', $highValueCustomerIds)->count(),
            'birthday_customers' => $birthdayCustomers,
            'average_rating' => round((float) OrderFeedback::query()->avg('rating'), 2),
            'thresholds' => [
                'inactive_days' => $inactiveDays,
                'recent_days' => $recentDays,
                'high_value_minimum_spend' => $highValueMinimumSpend,
            ],
        ];
    }
}
