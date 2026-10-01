<?php

namespace Modules\Core\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Append-only telemetry for NexDine Intelligence Layer recommendations
 * (shown / accepted / ignored / successful / failed), keyed by the
 * Recommendation `tracking_id`. Written only via {@see \Modules\Core\Intelligence\TelemetryRecorder}.
 */
class RecommendationTelemetry extends Model
{
    public $timestamps = false;

    protected $table = 'recommendation_telemetry';

    protected $fillable = [
        'tracking_id',
        'recommendation_type',
        'event',
        'branch_id',
        'user_id',
        'confidence',
        'meta',
        'created_at',
    ];

    protected $casts = [
        'meta' => 'array',
        'confidence' => 'float',
        'created_at' => 'datetime',
    ];
}
