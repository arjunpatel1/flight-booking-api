<?php

namespace Modules\Flight\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Flight\Enums\SupplierHealthStatus;
use Modules\Flight\Enums\SupplierType;
use Modules\Saas\Models\Tenant;
use Modules\Saas\Traits\BelongsToTenant;
use Modules\Support\Eloquent\Model;
use Modules\Support\Traits\HasFilters;
use Modules\Support\Traits\HasSortBy;

/**
 * Phase 5: Flight Supplier Model
 *
 * Represents an external flight supply source: GDS, NDC, Airline Direct API,
 * Consolidator, Contracted Pool, or Manual Offline Supplier.
 */
class FlightSupplier extends Model
{
    use BelongsToTenant, HasFilters, HasSortBy, SoftDeletes;

    protected $table = 'flight_suppliers';

    protected $fillable = [
        'tenant_id',
        'code',
        'name',
        'type',
        'adapter_class',
        'environment',
        'health_status',
        'is_active',
        'priority',
        'timeout_seconds',
        'last_health_check_at',
        'last_error_message',
        'consecutive_failures',
        'metadata',
    ];

    protected $casts = [
        'type'                 => SupplierType::class,
        'health_status'        => SupplierHealthStatus::class,
        'is_active'            => 'boolean',
        'priority'             => 'integer',
        'timeout_seconds'      => 'integer',
        'consecutive_failures' => 'integer',
        'last_health_check_at' => 'datetime',
        'metadata'             => 'array',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function configuration(): HasOne
    {
        return $this->hasOne(FlightSupplierConfiguration::class, 'flight_supplier_id');
    }

    public function credentials(): HasOne
    {
        return $this->hasOne(FlightSupplierCredential::class, 'flight_supplier_id');
    }

    public function mappings(): HasMany
    {
        return $this->hasMany(FlightSupplierMapping::class, 'flight_supplier_id');
    }

    public function healthLogs(): HasMany
    {
        return $this->hasMany(FlightSupplierHealthLog::class, 'flight_supplier_id');
    }

    // ── Scopes & Helpers ──────────────────────────────────────────────────────

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function scopeOperational(Builder $query): void
    {
        $query->where('is_active', true)
              ->whereIn('health_status', [SupplierHealthStatus::ACTIVE->value, SupplierHealthStatus::DEGRADED->value]);
    }

    public function isOperational(): bool
    {
        return $this->is_active && $this->health_status->isAvailable();
    }
}
