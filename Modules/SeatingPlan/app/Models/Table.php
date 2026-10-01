<?php

namespace Modules\SeatingPlan\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Modules\ActivityLog\Traits\HasActivityLog;
use Modules\Branch\Traits\HasBranch;
use Modules\Order\Models\Order;
use Modules\SeatingPlan\Database\Factories\TableFactory;
use Modules\SeatingPlan\Enums\TableShape;
use Modules\SeatingPlan\Enums\TableStatus;
use Modules\SeatingPlan\Events\TableUpdateStatus;
use Modules\Support\Eloquent\Model;
use Modules\Support\Traits\HasActiveStatus;
use Modules\Support\Traits\HasCreatedBy;
use Modules\Support\Traits\HasFilters;
use Modules\Support\Traits\HasOrder;
use Modules\Support\Traits\HasSortBy;
use Modules\Support\Traits\HasTagsCache;
use Modules\Support\Traits\HasUuid;
use Modules\Translation\Traits\Translatable;
use Modules\User\Models\User;

/**
 * @property int $id
 * @property string $name
 * @property int $floor_id
 * @property-read  Floor $floor
 * @property int $zone_id
 * @property-read  Zone $zone
 * @property-read TableMerge $merges
 * @property int|null $assigned_waiter_id
 * @property-read  User|null $waiter
 * @property int|null $current_merge_id
 * @property-read  TableMerge|null $currentMerge
 * @property int $capacity
 * @property TableStatus $status
 * @property TableShape $shape
 * @property-read  Order[] $orders
 * @property-read  Order $activeOrder
 * @property int $active_orders_guest_count
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 *
 * @method static Builder availableMerge()
 *
 */
class Table extends Model
{
    use SoftDeletes,
        HasTagsCache,
        HasCreatedBy,
        HasActiveStatus,
        Translatable,
        HasSortBy,
        HasBranch,
        HasFilters,
        HasOrder,
        HasUuid,
        HasFactory,
        HasActivityLog;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        "floor_id",
        "zone_id",
        "assigned_waiter_id",
        "current_merge_id",
        "name",
        "capacity",
        "status",
        "shape",
        "pos_x",
        "pos_y",
        "rotation",
        "scale",
        "active_orders_guest_count",
        self::ACTIVE_COLUMN_NAME,
        self::BRANCH_COLUMN_NAME,
    ];

    /**
     * The attributes that are translatable.
     *
     * @var array
     */
    protected array $translatable = ['name'];

    protected static function newFactory(): TableFactory
    {
        return TableFactory::new();
    }

    /**
     * Get floor
     *
     * @return BelongsTo
     */
    public function floor(): BelongsTo
    {
        return $this->belongsTo(Floor::class)
            ->withOutGlobalBranchPermission()
            ->withoutGlobalActive()
            ->withTrashed();
    }


    /**
     * Get zone
     *
     * @return BelongsTo
     */
    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class)
            ->withOutGlobalBranchPermission()
            ->withoutGlobalActive()
            ->withTrashed();
    }

    /** @inheritDoc */
    public function allowedFilterKeys(): array
    {
        return [
            "search",
            "from",
            "to",
            "floor",
            "floor_id",
            "zone_id",
            "status",
            "shape",
            self::ACTIVE_COLUMN_NAME,
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
        $query->whereLikeTranslation('name', $value);
    }

    /**
     * Get all table available merge
     *
     * @param Builder $query
     * @return void
     */
    public function scopeAvailableMerge(Builder $query): void
    {
        $query
            ->whereNull("current_merge_id")
            ->whereIn('status', [
                TableStatus::Available,
                TableStatus::Occupied,
                TableStatus::Reserved
            ]);
    }
    public function activeOrdersGuestCount(): Attribute
    {
        return Attribute::get(fn () =>
            $this->activeOrders()->sum('guest_count')
        );
    }

    /**
     * Get merges
     *
     * @return HasMany
     */
    public function merges(): HasMany
    {
        return $this->hasMany(TableMerge::class);
    }

    /**
     * Get current merge
     *
     * @return BelongsTo
     */
    public function currentMerge(): BelongsTo
    {
        return $this->belongsTo(TableMerge::class, "current_merge_id");
    }

    /**
     * Get assigned waiter
     *
     * @return BelongsTo
     */
    public function waiter(): BelongsTo
    {
        return $this->belongsTo(User::class, "assigned_waiter_id")
            ->withOutGlobalBranchPermission()
            ->withoutGlobalActive()
            ->withTrashed();
    }

    /**
     * Get table orders
     *
     * @return HasMany
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class)
            ->withOutGlobalBranchPermission();
    }

    /**
     * Get table active order
     *
     * @return HasOne
     */
    public function activeOrder(): HasOne
    {
        return $this->hasOne(Order::class)
            ->withOutGlobalBranchPermission()
            ->activeOrders();
    }
    
    /**
     * Get table active order
     *
     * @return HasOne
     */
    public function activeOrders(): HasMany
    {
        return $this->hasMany(Order::class)
            ->withOutGlobalBranchPermission()
            ->activeOrders();
    }
    

    /**
     * Store status log
     *
     * @param TableStatus $status
     * @param int|null $changedById
     * @param string|null $note
     * @return TableStatusLog
     */
    public function storeStatusLog(TableStatus $status, ?int $changedById = null, ?string $note = null): TableStatusLog
    {
        $log = $this->statusLogs()
            ->create([
                "status" => $status,
                "changed_by" => $changedById,
                "note" => $note
            ]);

        event(new TableUpdateStatus($this->fresh() ?? $this, $status, $changedById, $note));

        return $log;
    }

    /**
     * Get table status logs
     *
     * @return HasMany
     */
    public function statusLogs(): HasMany
    {
        return $this->hasMany(TableStatusLog::class)->orderBy("id", "desc");
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            "status" => TableStatus::class,
            "shape" => TableShape::class,
            "pos_x" => "float",
            "pos_y" => "float",
            "rotation" => "float",
            "scale" => "float",
            self::ACTIVE_COLUMN_NAME => 'boolean',
            self::ORDER_COLUMN_NAME => "int"
        ];
    }
}
