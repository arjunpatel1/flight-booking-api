<?php

namespace Modules\Printer\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentIncident extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'agent_id',
        'category',
        'confidence',
        'explanation',
        'technical_detail',
        'fix_steps',
        'support_score',
        'auto_fix_attempted',
        'auto_fix_result',
        'auto_fix_detail',
        'resolved_at',
        'detected_at',
        'created_at',
    ];

    protected $casts = [
        'fix_steps'          => 'array',
        'auto_fix_attempted' => 'boolean',
        'confidence'         => 'float',
        'support_score'      => 'integer',
        'resolved_at'        => 'datetime',
        'detected_at'        => 'datetime',
        'created_at'         => 'datetime',
    ];

    public const CATEGORIES = [
        'none',
        'printer_offline',
        'network_issue',
        'backend_unreachable',
        'queue_stuck',
        'database_error',
        'websocket_failure',
        'unknown',
    ];

    public const AUTO_FIX_RESULTS = ['success', 'failed', 'not_supported'];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(PrintAgent::class, 'agent_id');
    }
}
