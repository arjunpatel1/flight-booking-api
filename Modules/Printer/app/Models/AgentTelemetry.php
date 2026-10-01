<?php

namespace Modules\Printer\Models;

use Modules\Support\Eloquent\Model;

class AgentTelemetry extends Model
{
    protected $table = 'agent_telemetry';

    public $timestamps = false;

    protected $fillable = [
        'agent_id',
        'agent_uuid',
        'cpu_percent',
        'ram_used_mb',
        'ram_total_mb',
        'disk_used_gb',
        'disk_total_gb',
        'print_queue_depth',
        'voice_queue_depth',
        'print_count_today',
        'print_failures_today',
        'network_latency_ms',
        'heartbeat_success_rate',
        'ws_state',
        'uptime_seconds',
        'recorded_at',
    ];

    protected function casts(): array
    {
        return [
            'cpu_percent'            => 'float',
            'ram_used_mb'            => 'float',
            'ram_total_mb'           => 'float',
            'disk_used_gb'           => 'float',
            'disk_total_gb'          => 'float',
            'print_queue_depth'      => 'integer',
            'voice_queue_depth'      => 'integer',
            'print_count_today'      => 'integer',
            'print_failures_today'   => 'integer',
            'network_latency_ms'     => 'integer',
            'heartbeat_success_rate' => 'integer',
            'uptime_seconds'         => 'integer',
            'recorded_at'            => 'datetime',
        ];
    }

    public function agent()
    {
        return $this->belongsTo(PrintAgent::class, 'agent_id');
    }
}
