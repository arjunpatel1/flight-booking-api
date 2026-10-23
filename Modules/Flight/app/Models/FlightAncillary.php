<?php

namespace Modules\Flight\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Flight\Enums\AncillaryCategory;
use Modules\Saas\Models\Tenant;
use Modules\Support\Eloquent\Model;

class FlightAncillary extends Model
{
    use SoftDeletes;

    protected $table = 'flight_ancillaries';

    protected $fillable = [
        'tenant_id',
        'flight_inventory_id',
        'category',
        'code',
        'name',
        'description',
        'price',
        'currency',
        'max_quantity_per_pax',
        'available_quantity',
        'supplier_code',
        'status',
        'meta',
    ];

    protected $casts = [
        'category'             => AncillaryCategory::class,
        'price'                => 'decimal:2',
        'max_quantity_per_pax' => 'integer',
        'available_quantity'   => 'integer',
        'meta'                 => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function flightInventory(): BelongsTo
    {
        return $this->belongsTo(FlightInventory::class, 'flight_inventory_id');
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('status', 'ACTIVE');
    }

    public function scopeForCategory(Builder $query, AncillaryCategory|string $category): void
    {
        $val = $category instanceof AncillaryCategory ? $category->value : $category;
        $query->where('category', $val);
    }
}
