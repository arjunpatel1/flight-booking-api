<?php

namespace Modules\Printer\Models;

use Modules\Support\Eloquent\Model;

class AgentCommand extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'agent_id',
        'command',
        'payload',
        'status',
        'issued_by',
        'issued_at',
        'acknowledged_at',
        'executed_at',
        'result',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'payload'         => 'array',
            'issued_at'       => 'datetime',
            'acknowledged_at' => 'datetime',
            'executed_at'     => 'datetime',
        ];
    }

    public function agent()
    {
        return $this->belongsTo(PrintAgent::class, 'agent_id');
    }
}
