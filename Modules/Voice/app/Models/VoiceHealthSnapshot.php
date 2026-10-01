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
 * @property string $overall
 * @property string $voice_service_state
 * @property string $tts_engine_state
 * @property string $audio_device_state
 * @property string $queue_state
 * @property string $websocket_state
 * @property string $backend_state
 * @property string $last_announcement_state
 * @property array|null $subsystems
 * @property \Illuminate\Support\Carbon $checked_at
 * @property \Illuminate\Support\Carbon $created_at
 */
class VoiceHealthSnapshot extends Model
{
    use HasBranch;

    public const UPDATED_AT = null;

    public const STATES = ['healthy', 'warning', 'degraded', 'critical', 'offline'];

    protected $table = 'voice_health_snapshots';

    protected $fillable = [
        'branch_id',
        'agent_id',
        'overall',
        'voice_service_state',
        'tts_engine_state',
        'audio_device_state',
        'queue_state',
        'websocket_state',
        'backend_state',
        'last_announcement_state',
        'subsystems',
        'checked_at',
    ];

    protected $casts = [
        'subsystems' => 'array',
        'checked_at' => 'datetime',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(PrintAgent::class, 'agent_id');
    }
}
