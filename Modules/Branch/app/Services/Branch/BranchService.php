<?php

namespace Modules\Branch\Services\Branch;

use App\NexDine;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Branch\Models\Branch;
use Modules\Currency\Currency;
use Modules\Order\Enums\OrderType;
use Modules\Payment\Enums\PaymentMethod;
use Modules\Saas\Models\Tenant;
use Modules\Saas\Services\FeatureLimit\FeatureLimitServiceInterface;
use Modules\Support\Country;
use Modules\Support\GlobalStructureFilters;
use Modules\Support\TimeZone;

class BranchService implements BranchServiceInterface
{
    public function __construct(protected FeatureLimitServiceInterface $featureLimitService)
    {
    }

    /** @inheritDoc */
    public function label(): string
    {
        return __("branch::branches.branch");
    }

    /** @inheritDoc */
    public function get(array $filters = [], ?array $sorts = []): LengthAwarePaginator
    {
        return $this->getModel()
            ->withoutGlobalActive()
            ->filters($filters)
            ->sortBy($sorts)
            ->paginate(NexDine::paginate())
            ->withQueryString();
    }

    /** @inheritDoc */
    public function getModel(): Branch
    {
        return new ($this->model());
    }

    /** @inheritDoc */
    public function model(): string
    {
        return Branch::class;
    }

    /** @inheritDoc */
    public function show(int $id): Branch
    {
        return $this->findOrFail($id);
    }

    /** @inheritDoc */
    public function findOrFail(int $id): Builder|array|EloquentCollection|Branch
    {
        return $this->getModel()
            ->withoutGlobalActive()
            ->findOrFail($id);
    }

    /** @inheritDoc */
    public function store(array $data): Branch
    {
        return DB::transaction(function () use ($data) {
            $tenantId = $data['tenant_id'] ?? null;
            $this->ensureTenantCapacity($tenantId);

            $branch = $this->getModel()->create($data);

            if ($tenantId !== null) {
                $this->featureLimitService->recordUsage($tenantId, 'branches');
            }

            return $branch;
        });
    }

    /** @inheritDoc */
    public function update(int $id, array $data): Branch
    {
        return DB::transaction(function () use ($id, $data) {
            $branch = $this->findOrFail($id);
            $currentTenantId = $branch->tenant_id;
            $nextTenantId = $data['tenant_id'] ?? null;

            if ($currentTenantId !== $nextTenantId) {
                $this->ensureTenantCapacity($nextTenantId);
            }

            $branch->update($data);

            if ($currentTenantId !== $nextTenantId) {
                if ($currentTenantId !== null) {
                    $this->featureLimitService->releaseUsage($currentTenantId, 'branches');
                }

                if ($nextTenantId !== null) {
                    $this->featureLimitService->recordUsage($nextTenantId, 'branches');
                }
            }

            return $branch;
        });
    }

    /** @inheritDoc */
    public function destroy(int|array|string $ids): bool
    {
        return DB::transaction(function () use ($ids) {
            $branches = $this->getModel()
                ->withoutGlobalActive()
                ->notMain()
                ->whereIn("id", parseIds($ids))
                ->get();

            $deleted = $branches->isNotEmpty()
                && $this->getModel()
                    ->withoutGlobalActive()
                    ->notMain()
                    ->whereIn("id", $branches->modelKeys())
                    ->delete();

            if (! $deleted) {
                return false;
            }

            $branches
                ->whereNotNull('tenant_id')
                ->groupBy('tenant_id')
                ->each(fn ($tenantBranches, $tenantId) => $this->featureLimitService
                    ->releaseUsage((int) $tenantId, 'branches', $tenantBranches->count()));

            return true;
        });
    }

    /** @inheritDoc */
    public function getStructureFilters(): array
    {
        return [
            GlobalStructureFilters::active(),
            GlobalStructureFilters::from(),
            GlobalStructureFilters::to(),
        ];
    }

    /** @inheritDoc */
    public function getFormMeta(): array
    {
        return [
            "timezones" => TimeZone::toList(),
            "countries" => Country::supportedList(),
            "currencies" => Currency::supportedList(),
            "order_types" => OrderType::toArrayTrans(),
            "payment_methods" => PaymentMethod::toArrayTrans(),
            "tenants" => Tenant::query()
                ->withoutGlobalActive()
                ->select('id', 'name')
                ->orderBy('name')
                ->get(),
        ];
    }

    protected function ensureTenantCapacity(?int $tenantId): void
    {
        if ($tenantId === null || $this->featureLimitService->hasCapacity($tenantId, 'branches')) {
            return;
        }

        throw ValidationException::withMessages([
            'tenant_id' => __('saas::tenants.feature_limit_reached', [
                'feature' => __('saas::subscription_plans.features.branches'),
            ]),
        ]);
    }
}
