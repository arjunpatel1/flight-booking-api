<?php

namespace Modules\Flight\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Flight\Enums\SupplierHealthStatus;
use Modules\Support\Eloquent\Model;

/**
 * Phase 5: Supplier Health Log
 *
 * Records latency and health status snapshots from background ping
 * or live API call monitoring.
 */
class FlightSupplierHealthLog extends Model
{
    protected $table = 'flight_supplier_health_logs';

    protected $fillable = [
        'flight_supplier_id',
        'status',
        'latency_ms',
        'endpoint',
        'is_healthy',
        'error_message',
        'checked_at',
    ];

    protected $casts = [
        'status'      => SupplierHealthStatus::class,
        'latency_ms'  => 'integer',
        'is_healthy'  => 'boolean',
        'checked_at'  => 'datetime',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(FlightSupplier::class, 'flight_supplier_id');
    }
}
