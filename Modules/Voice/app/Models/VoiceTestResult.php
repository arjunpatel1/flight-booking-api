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
 * @property string $test_type
 * @property string $status
 * @property string|null $message
 * @property int|null $duration_ms
 * @property string|null $device_used
 * @property string|null $error
 * @property string $triggered_by
 * @property \Illuminate\Support\Carbon $created_at
 */
class VoiceTestResult extends Model
{
    use HasBranch;

    public const UPDATED_AT = null;

    public const TEST_TYPES = ['speaker', 'tts', 'pipeline', 'custom'];
    public const STATUSES   = ['success', 'failed', 'pending'];

    protected $fillable = [
        'branch_id',
        'agent_id',
        'test_type',
        'status',
        'message',
        'duration_ms',
        'device_used',
        'error',
        'triggered_by',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(PrintAgent::class, 'agent_id');
    }
}
