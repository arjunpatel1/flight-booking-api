<?php

namespace Modules\SeatingPlan\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Modules\Branch\Traits\HasBranch;
use Modules\SeatingPlan\Enums\TableMergeType;
use Modules\Support\Eloquent\Model;
use Modules\Support\Traits\HasCreatedBy;
use Modules\Support\Traits\HasFilters;
use Modules\Support\Traits\HasSortBy;
use Modules\User\Models\User;


/**
 * @property int $id
 * @property int $table_id
 * @property-read Table $table
 * @property int|null $closed_by
 * @property-read User|null $closedBy
 * @property TableMergeType $type
 * @property string|null $notes
 * @property-read Collection<TableMergeMember> $members
 * @property-read MergeSnapshot $snapshot
 * @property Carbon|null $closed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class TableMerge extends Model
{
    use SoftDeletes,
        HasCreatedBy,
        HasFilters,
        HasSortBy,
        HasBranch;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        "table_id",
        "closed_by",
        "type",
        "notes",
        "closed_at",
        self::BRANCH_COLUMN_NAME
    ];

    /**
     * Get main table
     *
     * @return BelongsTo
     */
    public function table(): BelongsTo
    {
        return $this->belongsTo(Table::class)
            ->withOutGlobalBranchPermission()
            ->withoutGlobalActive()
            ->withTrashed();
    }

    /**
     * Get closed by
     *
     * @return BelongsTo
     */
    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, "closed_by")
            ->withOutGlobalBranchPermission()
            ->withoutGlobalActive()
            ->withTrashed();
    }

    /**
     * Get table merge members
     *
     * @return HasMany
     */
    public function members(): HasMany
    {
        return $this->hasMany(TableMergeMember::class)->orderby("is_main", "desc");
    }

    /**
     * Get table merge snapshot
     *
     * @return HasOne
     */
    public function snapshot(): HasOne
    {
        return $this->hasOne(MergeSnapshot::class);
    }

    /** @inheritDoc */
    public function allowedFilterKeys(): array
    {
        return [
            "from",
            "to",
            "type",
            "created_by",
            "closed_by",
            self::BRANCH_COLUMN_NAME
        ];
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            "type" => TableMergeType::class,
            "closed_at" => "datetime",
        ];
    }

    /** @inheritDoc */
    protected function getSortableAttributes(): array
    {
        return [
            "type",
            "closed_at",
            "created_by",
            "closed_by",
            "table_id",
            "type",
            "closed_at",
            self::BRANCH_COLUMN_NAME,
        ];
    }
}
