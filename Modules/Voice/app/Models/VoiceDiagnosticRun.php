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
 * @property bool $all_passed
 * @property int $duration_ms
 * @property string $backend_connectivity
 * @property string $websocket_connectivity
 * @property string $audio_device_test
 * @property string $tts_engine_test
 * @property string $voice_queue_health
 * @property string $end_to_end_test
 * @property array|null $results
 * @property \Illuminate\Support\Carbon $ran_at
 * @property \Illuminate\Support\Carbon $created_at
 */
class VoiceDiagnosticRun extends Model
{
    use HasBranch;

    public const UPDATED_AT = null;

    public const STATUSES = ['pass', 'warn', 'fail'];

    // Maps agent test names to model columns.
    public const TEST_COLUMN_MAP = [
        'Backend Connectivity' => 'backend_connectivity',
        'WebSocket Connectivity' => 'websocket_connectivity',
        'Audio Device Test' => 'audio_device_test',
        'TTS Engine Test' => 'tts_engine_test',
        'Voice Queue Health' => 'voice_queue_health',
        'End-to-End Test' => 'end_to_end_test',
    ];

    protected $table = 'voice_diagnostic_runs';

    protected $fillable = [
        'branch_id',
        'agent_id',
        'all_passed',
        'duration_ms',
        'backend_connectivity',
        'websocket_connectivity',
        'audio_device_test',
        'tts_engine_test',
        'voice_queue_health',
        'end_to_end_test',
        'results',
        'ran_at',
    ];

    protected $casts = [
        'all_passed' => 'boolean',
        'duration_ms' => 'integer',
        'results' => 'array',
        'ran_at' => 'datetime',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(PrintAgent::class, 'agent_id');
    }
}
