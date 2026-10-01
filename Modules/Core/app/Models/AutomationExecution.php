<?php

namespace Modules\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Automation\AutomationExecutionState;

/**
 * Audit + lifecycle record for a single automation execution (RAE v2).
 * Written only via the AutomationExecutor — one row per execution attempt.
 */
class AutomationExecution extends Model
{
    protected $table = 'automation_executions';

    protected $fillable = [
        'automation_id',
        'domain',
        'trigger',
        'action_key',
        'state',
        'idempotency_key',
        'executor_id',
        'branch_id',
        'device_id',
        'result',
        'rollback_available',
        'rollback_payload',
        'rolled_back_at',
        'failure_reason',
        'started_at',
        'completed_at',
        'duration_ms',
    ];

    protected $casts = [
        'state' => AutomationExecutionState::class,
        'result' => 'array',
        'rollback_payload' => 'array',
        'rollback_available' => 'boolean',
        'rolled_back_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function toPayload(): array
    {
        return [
            'id' => $this->id,
            'automation_id' => $this->automation_id,
            'domain' => $this->domain,
            'trigger' => $this->trigger,
            'action_key' => $this->action_key,
            'state' => $this->state instanceof AutomationExecutionState ? $this->state->value : $this->state,
            'executor_id' => $this->executor_id,
            'branch_id' => $this->branch_id,
            'result' => $this->result,
            'rollback_available' => (bool) $this->rollback_available,
            'rolled_back_at' => $this->rolled_back_at?->toISOString(),
            'failure_reason' => $this->failure_reason,
            'duration_ms' => $this->duration_ms,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
