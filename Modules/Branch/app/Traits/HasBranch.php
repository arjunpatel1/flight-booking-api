<?php

namespace Modules\Branch\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;
use Modules\Branch\Models\Branch;
use Modules\Support\Scopes\ActiveScope;
use Modules\Tax\Models\Tax;

/**
 * @property int|null $branch_id
 * @property Branch|null $branch
 * @property string $currency
 *
 * @method static Builder|static whereBranch(int $id)
 * @method static Builder|static withOutGlobalBranchPermission()
 */
trait HasBranch
{
    /**
     * The branch key.
     *
     * @var string
     */
    const BRANCH_COLUMN_NAME = 'branch_id';

    /**
     * Boot HasActiveStatus trait
     *
     * @return void
     */
    public static function bootHasBranch(): void
    {
        static::addGlobalScope("branch_permission", function (Builder $builder) {
            if (! auth()->guard()->hasUser()) {
                return;
            }

            $user = auth()->user();
            if (! $user?->assignedToBranch()) {
                return;
            }

            $model = $builder->getModel();
            $builder->where(
                fn(Builder $query) => $query
                    ->where("{$model->getTable()}.branch_id", $user->branch_id)
                    ->when($model->getMorphClass() === Tax::class, fn(Builder $query) => $query->orWhereNull("{$model->getTable()}.branch_id"))
            );
        });

        static::addGlobalScope("tenant_branch_permission", function (Builder $builder) {
            if (! auth()->guard()->hasUser()) {
                return;
            }

            $user = auth()->user();
            $isPlatformAdmin = $user?->isSuperAdmin()
                && ! $user->assignedToTenant()
                && ! $user->assignedToBranch();

            if (! $user || $user->assignedToBranch() || ! $user->assignedToTenant() || $isPlatformAdmin) {
                return;
            }

            $table = $builder->getModel()->getTable();

            $builder->where(function (Builder $query) use ($user, $table) {
                $query->whereIn("{$table}.branch_id", function ($sub) use ($user) {
                    $sub->select('id')
                        ->from('branches')
                        ->where('tenant_id', $user->tenant_id);
                });

                // Tenant-level records carry no branch_id and are owned through
                // their own tenant_id column. Without this branch a tenant admin
                // cannot see itself, its tenant-level colleagues, or anything it
                // creates before the first branch exists. Isolation still holds:
                // the row must belong to the caller's tenant.
                if (static::tableHasTenantColumn($table)) {
                    $query->orWhere(fn(Builder $inner) => $inner
                        ->whereNull("{$table}.branch_id")
                        ->where("{$table}.tenant_id", $user->tenant_id));
                }
            });
        });

        static::creating(function ($model) {
            $user = auth()->user();
            if ($user?->assignedToBranch()) {
                $model->branch_id = $user->branch_id;
            }
        });
    }

    /**
     * Whether the given table owns a tenant_id column. Memoised per request so
     * the global scope never pays for a schema lookup on the hot path.
     *
     * @var array<string, bool>
     */
    protected static array $tenantColumnCache = [];

    protected static function tableHasTenantColumn(string $table): bool
    {
        return static::$tenantColumnCache[$table] ??= Schema::hasColumn($table, 'tenant_id');
    }

    /**
     * Get a model branch
     *
     * @return BelongsTo
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, self::BRANCH_COLUMN_NAME)
            ->withTrashed()
            ->withoutGlobalScope(ActiveScope::class);
    }

    /**
     * Scope a query to only include records a specific branch.
     *
     * @param Builder $query
     * @param int $id
     * @return void
     */
    public function scopeWhereBranch(Builder $query, int $id): void
    {
        $query->where(static::BRANCH_COLUMN_NAME, $id);
    }

    /**
     * Scope a query without global branch permission scope.
     *
     * @param Builder $query
     * @return void
     */
    public function scopeWithOutGlobalBranchPermission(Builder $query): void
    {
        $query
            ->withOutGlobalScope("branch_permission")
            ->withOutGlobalScope("tenant_branch_permission");
    }
}
