<?php

namespace Modules\User\Services\User;

use App\NexDine;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Arr;
use Modules\Branch\Models\Branch;
use Modules\Category\Models\Category;
use Modules\Order\Enums\OrderType;
use Modules\Printer\Models\Printer;
use Modules\Support\GlobalStructureFilters;
use Modules\User\Enums\DefaultRole;
use Modules\User\Enums\GenderType;
use Modules\User\Models\Role;
use Modules\User\Models\User;

class UserService implements UserServiceInterface
{
    /** @inheritDoc */
    public function label(): string
    {
        return __("user::users.user");
    }

    /** @inheritDoc */
    public function get(array $filters = [], ?array $sorts = []): LengthAwarePaginator
    {
        $excludeRoles = collect((array) ($filters['exclude_roles'] ?? []))
            ->filter()
            ->map(fn ($role) => (string) $role)
            ->values()
            ->all();
        unset($filters['exclude_roles']);

        // Older staff records predate the explicit can_login flag and carry
        // NULL. They are login-capable users for compatibility; excluding
        // them made both old and newly migrated accounts disappear from the
        // Users registry when the frontend requested can_login=true.
        $canLogin = Arr::pull($filters, 'can_login');

        return $this->getModel()
            ->query()
            ->withOutGlobalBranchPermission()
            ->withoutGlobalActive()
            ->role(roles: DefaultRole::Customer, without: true)
            ->when(auth()->user(), fn (Builder $query, User $actor) => $this->scopeVisibleToActor($query, $actor))
            ->when(
                $excludeRoles !== [],
                fn (Builder $query) => $query->whereDoesntHave(
                    'roles',
                    fn (Builder $roleQuery) => $roleQuery->whereIn('name', $excludeRoles)
                )
            )
            ->with(["branch:id,name", "files"])
            ->when($canLogin !== null && $canLogin !== '', function (Builder $query) use ($canLogin) {
                $enabled = filter_var($canLogin, FILTER_VALIDATE_BOOLEAN);

                $query->where(function (Builder $loginQuery) use ($enabled) {
                    $loginQuery->where('can_login', $enabled);
                    if ($enabled) {
                        $loginQuery->orWhereNull('can_login');
                    }
                });
            })
            ->filters($filters)
            ->sortBy($sorts)
            ->paginate(NexDine::paginate())
            ->withQueryString();
    }

    /** @inheritDoc */
    public function getModel(): User
    {
        return new ($this->model());
    }

    /** @inheritDoc */
    public function model(): string
    {
        return User::class;
    }

    /** @inheritDoc */
    public function show(int $id): User
    {
        return $this->findOrFail($id);
    }

    /** @inheritDoc */
    public function findOrFail(int $id): Builder|array|EloquentCollection|User
    {
        return $this->getModel()
            ->query()
            ->withOutGlobalBranchPermission()
            ->role(roles: DefaultRole::Customer, without: true)
            ->withoutGlobalActive()
            ->when(auth()->user(), fn (Builder $query, User $actor) => $this->scopeVisibleToActor($query, $actor))
            ->with([
                "branch:id,name,tenant_id",
                "files",
                "printer:id,name",
                "roles:id,name,display_name",
            ])
            ->findOrFail($id);
    }

    /** @inheritDoc */
    public function store(array $data): User
    {
        $data['order_types'] = $this->normalizeOrderTypesForRole($data);
        $roles = $data['roles'] ?? [$data['role']];
        $data['branch_id'] = $this->isEnterpriseRoleSelection($roles) ? null : ($data['branch_id'] ?? null);
        $data['tenant_id'] = $this->resolveTenantId($data);

        $user = $this->getModel()->query()->create(Arr::except($data, ["role", "roles"]));

        $user->syncRoles($roles);

        return $user;
    }

    /** @inheritDoc */
    public function update(int $id, array $data): User
    {
        $user = $this->findOrFail($id);

        $data['order_types'] = $this->normalizeOrderTypesForRole($data);
        $roles = $data['roles'] ?? [$data['role']];
        $data['branch_id'] = $this->isEnterpriseRoleSelection($roles) ? null : ($data['branch_id'] ?? null);
        $data['tenant_id'] = $user->tenant_id ?? $this->resolveTenantId($data);

        if ($user->isMainUser()) {
            $data["is_active"] = true;
        }

        $exceptAttributes = ["role", "roles"];

        if (empty($data['password'])) {
            $exceptAttributes[] = "password";
        }

        $user->update(Arr::except($data, $exceptAttributes));

        if (!$user->isMainUser()) {
            $user->syncRoles($roles);
        }

        return $user;
    }

    private function resolveTenantId(array $data): ?int
    {
        $actor = auth()->user();
        if ($actor?->assignedToTenant() && ! $actor->isSuperAdmin()) {
            return $actor->tenantId();
        }

        if (filled($data['branch_id'] ?? null)) {
            return Branch::query()
                ->withoutGlobalScopes()
                ->whereKey($data['branch_id'])
                ->value('tenant_id');
        }

        return filled($data['tenant_id'] ?? null) ? (int) $data['tenant_id'] : null;
    }

    /** @inheritDoc */
    public function destroy(int|array|string $ids): bool
    {
        return $this->getModel()
            ->query()
            ->withOutGlobalBranchPermission()
            ->role(roles: DefaultRole::Customer, without: true)
            ->withoutGlobalActive()
            ->when(auth()->user(), fn (Builder $query, User $actor) => $this->scopeVisibleToActor($query, $actor))
            ->whereNot("id", 1)
            ->whereIn("id", parseIds($ids))
            ->delete() ?: false;
    }

    /** @inheritDoc */
    public function getStructureFilters(): array
    {
        $branchFilter = GlobalStructureFilters::branch();
        return [
            ...(is_null($branchFilter) ? [] : [$branchFilter]),
            [
                "key" => 'role',
                "label" => __('user::users.filters.role'),
                "type" => 'select',
                "options" => Role::list(auth()->user()->assignedToTenant()),
            ],
            [
                "key" => 'gender',
                "label" => __('user::users.filters.gender'),
                "type" => 'select',
                "options" => GenderType::toArrayTrans(),
            ],
            GlobalStructureFilters::active(),
            GlobalStructureFilters::from(),
            GlobalStructureFilters::to(),
        ];
    }

    /** @inheritDoc */
    public function getFormMeta(?int $branchId = null): array
    {
        if (is_null($branchId)) {
            return [
                "roles" => Role::list(auth()->user()->assignedToTenant()),
                "branches" => Branch::list(),
                "genders" => GenderType::toArrayTrans(),
                "categories" => Category::listWithSlug(),
                "order_types" => OrderType::toArrayTrans(),
                "countries" => \Modules\Support\Country::toList(),
            ];
        } else {
            $branchOrderTypes = Branch::withoutGlobalActive()
                ->find($branchId)
                ?->order_types ?: [];

            return [
                "printers" => Printer::list($branchId),
                "order_types" => array_values(array_filter(
                    OrderType::toArrayTrans(),
                    fn(array $orderType) => in_array($orderType['id'], $branchOrderTypes, true)
                )),
            ];
        }
    }

    private function normalizeOrderTypesForRole(array $data): ?array
    {
        $roleIds = collect($data['roles'] ?? [$data['role'] ?? null])->filter()->map(fn ($id) => (int) $id);
        $hasWaiterRole = $roleIds->isNotEmpty()
            && Role::query()->whereIn('id', $roleIds)->where('name', DefaultRole::Waiter->value)->exists();

        if (!$hasWaiterRole) {
            return null;
        }

        $validOrderTypes = OrderType::values();

        $orderTypes = collect($data['order_types'] ?? [])
            ->filter(fn($type) => is_string($type) || is_numeric($type))
            ->map(fn($type) => (string) $type)
            ->filter(fn(string $type) => in_array($type, $validOrderTypes, true))
            ->unique()
            ->values()
            ->all();

        return empty($orderTypes) ? null : $orderTypes;
    }

    private function isEnterpriseRoleSelection(array $roleIds): bool
    {
        return Role::query()
            ->whereIn('id', $roleIds)
            ->where('name', DefaultRole::EnterpriseAdmin->value)
            ->exists();
    }

    /**
     * Apply explicit user ownership visibility.
     *
     * The User model carries branch and tenant global scopes for most tables,
     * but admin screens need to edit tenant-level users such as enterprise
     * owners whose branch_id is intentionally null. We disable the implicit
     * branch scope above and replace it with a clear, auditable rule here.
     */
    private function scopeVisibleToActor(Builder $query, User $actor): Builder
    {
        if ($actor->isSuperAdmin() && ! $actor->assignedToTenant() && ! $actor->assignedToBranch()) {
            return $query;
        }

        if ($actor->assignedToBranch()) {
            return $query->where(function (Builder $visible) use ($actor) {
                $visible
                    ->where('branch_id', $actor->branchId())
                    ->orWhere(function (Builder $tenantOwner) use ($actor) {
                        $tenantOwner
                            ->whereNull('branch_id')
                            ->where('tenant_id', $actor->tenantId());
                    });
            });
        }

        if ($actor->assignedToTenant()) {
            return $query->where(function (Builder $visible) use ($actor) {
                $visible
                    ->where('tenant_id', $actor->tenantId())
                    ->orWhereHas('branch', function (Builder $branch) use ($actor) {
                        $branch
                            ->withoutGlobalScopes()
                            ->where('tenant_id', $actor->tenantId());
                    });
            });
        }

        return $query->whereNull('tenant_id')->whereNull('branch_id');
    }
}
