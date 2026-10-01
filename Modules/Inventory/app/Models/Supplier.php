<?php

namespace Modules\Inventory\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Modules\ActivityLog\Traits\HasActivityLog;
use Modules\Branch\Traits\HasBranch;
use Modules\Inventory\Database\Factories\SupplierFactory;
use Modules\Support\Eloquent\Model;
use Modules\Support\Traits\HasCreatedBy;
use Modules\Support\Traits\HasFilters;
use Modules\Support\Traits\HasSortBy;
use Modules\Support\Traits\HasTagsCache;

/**
 * @property int $id
 * @property string $name
 * @property string|null $address
 * @property string|null $phone
 * @property string|null $email
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class Supplier extends Model
{
    use HasFactory,
        HasSortBy,
        HasFilters,
        SoftDeletes,
        HasTagsCache,
        HasCreatedBy,
        HasBranch,
        HasActivityLog;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'name',
        'email',
        'phone',
        'address',
        self::BRANCH_COLUMN_NAME,
    ];

    /**
     * Get a list of all suppliers.
     *
     * @param int|null $branchId
     * @return Collection
     */
    public static function list(?int $branchId = null): Collection
    {
        return Cache::tags("suppliers")
            ->rememberForever(
                makeCacheKey(
                    [
                        'suppliers',
                        is_null($branchId) ? 'all' : "branch-{$branchId}",
                        'list'
                    ],
                    false
                ),
                fn() => static::select('id', 'name')
                    ->when(!is_null($branchId), fn($query) => $query->whereBranch($branchId))
                    ->get()
                    ->map(fn(Supplier $supplier) => [
                        'id' => $supplier->id,
                        'name' => $supplier->name
                    ])
            );
    }

    protected static function newFactory(): SupplierFactory
    {
        return SupplierFactory::new();
    }

    /** @inheritDoc */
    public function allowedFilterKeys(): array
    {
        return [
            "search",
            "from",
            "to",
            self::BRANCH_COLUMN_NAME
        ];
    }

    /**
     * Scope a query to search across all fields.
     *
     * @param Builder $query
     * @param string $value
     * @return void
     */
    public function scopeSearch(Builder $query, string $value): void
    {
        $query->where(function (Builder $query) use ($value) {
            $query->like('name', $value)
                ->orLike('phone', $value)
                ->orLike('email', $value);
        });
    }

    /** @inheritDoc */
    protected function getSortableAttributes(): array
    {
        return [
            "name",
            "email",
            "phone",
        ];
    }
}
