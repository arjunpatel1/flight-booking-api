<?php

namespace Modules\Voice\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Branch\Traits\HasBranch;
use Modules\Printer\Models\PrintAgent;
use Modules\Support\Eloquent\Model;

/**
 * @property int $id
 * @property int $branch_id
 * @property int|null $agent_id
 * @property string $type
 * @property string $severity
 * @property string $message
 * @property array|null $context
 * @property string $status
 * @property \Illuminate\Support\Carbon $fired_at
 * @property \Illuminate\Support\Carbon|null $resolved_at
 * @property int|null $resolved_by
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class VoiceAlert extends Model
{
    use HasBranch;

    public const ALERT_TYPES = [
        'voice_offline',
        'tts_failure',
        'audio_device_missing',
        'queue_backlog',
        'announcement_failure',
    ];

    public const SEVERITIES = ['warning', 'critical'];
    public const STATUSES   = ['active', 'acknowledged', 'resolved'];

    protected $fillable = [
        'branch_id',
        'agent_id',
        'type',
        'severity',
        'message',
        'context',
        'status',
        'fired_at',
        'resolved_at',
        'resolved_by',
    ];

    protected $casts = [
        'context'     => 'array',
        'fired_at'    => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(PrintAgent::class, 'agent_id');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}
