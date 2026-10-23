<?php

namespace Modules\Flight\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Saas\Models\Tenant;
use Modules\Saas\Traits\BelongsToTenant;
use Modules\Support\Eloquent\Model;
use Modules\Support\Traits\HasFilters;
use Modules\Support\Traits\HasSortBy;

/**
 * Phase 5: Supplier Configuration
 *
 * Stores endpoint URLs, timeout thresholds, retry settings, supported operations,
 * and rate-limiting limits for a supplier.
 */
class FlightSupplierConfiguration extends Model
{
    use BelongsToTenant, HasFilters, HasSortBy;

    protected $table = 'flight_supplier_configurations';

    protected $fillable = [
        'flight_supplier_id',
        'tenant_id',
        'endpoint_url',
        'backup_endpoint_url',
        'currency',
        'supported_cabins',
        'supported_operations',
        'timeout_ms',
        'retry_count',
        'rate_limit_per_minute',
        'extra_settings',
        'is_enabled',
    ];

    protected $casts = [
        'supported_cabins'     => 'array',
        'supported_operations' => 'array',
        'extra_settings'       => 'array',
        'timeout_ms'           => 'integer',
        'retry_count'          => 'integer',
        'rate_limit_per_minute'=> 'integer',
        'is_enabled'           => 'boolean',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(FlightSupplier::class, 'flight_supplier_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function supportsOperation(string $operation): bool
    {
        if (empty($this->supported_operations)) {
            return true;
        }

        return in_array($operation, $this->supported_operations, true);
    }
}
