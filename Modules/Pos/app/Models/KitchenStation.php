<?php

namespace Modules\Pos\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\ActivityLog\Traits\HasActivityLog;
use Modules\Branch\Models\Branch;
use Modules\Branch\Traits\HasBranch;
use Modules\Category\Models\Category;
use Modules\Printer\Models\Printer;
use Modules\Support\Eloquent\Model;
use Modules\Support\Traits\HasActiveStatus;
use Modules\Support\Traits\HasCreatedBy;
use Modules\Support\Traits\HasSortBy;
use Modules\Support\Traits\HasTagsCache;
use Modules\Translation\Traits\Translatable;

class KitchenStation extends Model
{
    use HasActivityLog, HasActiveStatus, HasBranch, HasCreatedBy, HasSortBy, HasTagsCache, SoftDeletes, Translatable;

    protected $fillable = [
        'name',
        'description',
        'branch_id',
        'display_order',
        'is_active',
        'prep_time_minutes',
        'max_concurrent_items',
        'printer_id',
        'color',
        'sound_enabled',
        'auto_bump_minutes',
    ];

    protected array $translatable = ['name', 'description'];

    protected $casts = [
        'is_active' => 'boolean',
        'prep_time_minutes' => 'integer',
        'max_concurrent_items' => 'integer',
        'sound_enabled' => 'boolean',
        'auto_bump_minutes' => 'integer',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function categories(): HasMany
    {
        return $this->hasMany(KitchenStationCategory::class);
    }

    public function printer(): BelongsTo
    {
        return $this->belongsTo(Printer::class);
    }

    public function assignedCategories()
    {
        return $this->belongsToMany(Category::class, 'kitchen_station_categories')
            ->withPivot('priority', 'prep_time_override')
            ->orderByPivot('priority');
    }

    public function orderProducts(): HasMany
    {
        return $this->hasMany(KitchenStationOrderProduct::class);
    }

    protected function getSortableAttributes(): array
    {
        return ['branch_id', 'display_order', 'is_active', 'prep_time_minutes'];
    }

    /**
     * Get active stations for a branch.
     */
    public static function getActiveStations(?int $branchId = null): \Illuminate\Support\Collection
    {
        return static::where('is_active', true)
            ->when($branchId, fn($q) => $q->where('branch_id', $branchId))
            ->orderBy('display_order')
            ->with(['assignedCategories'])
            ->get();
    }

    /**
     * Get stations that handle a specific category.
     */
    public static function getStationsForCategory(int $categoryId, ?int $branchId = null): \Illuminate\Support\Collection
    {
        return static::where('is_active', true)
            ->when($branchId, fn($q) => $q->where('branch_id', $branchId))
            ->whereHas('assignedCategories', fn($q) => $q->where('categories.id', $categoryId))
            ->orderBy('display_order')
            ->get();
    }
}
