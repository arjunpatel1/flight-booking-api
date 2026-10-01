<?php

namespace Modules\Printer\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'agent_id',
        'level',
        'message',
        'source_context',
        'exception',
        'context',
        'logged_at',
        'uploaded_at',
    ];

    protected $casts = [
        'context'     => 'array',
        'logged_at'   => 'datetime',
        'uploaded_at' => 'datetime',
    ];

    public const LEVELS = ['trace', 'debug', 'info', 'warning', 'error', 'fatal'];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(PrintAgent::class, 'agent_id');
    }
}
