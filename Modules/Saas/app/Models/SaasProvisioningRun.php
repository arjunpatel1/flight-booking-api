<?php

namespace Modules\Saas\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Saas\Events\ProvisioningStepCompleted;
use Modules\Saas\Events\ProvisioningStepFailed;
use Modules\Saas\Events\ProvisioningStepStarted;
use Modules\Saas\Events\TenantProvisioningCancelled;
use Modules\Saas\Events\TenantProvisioningCompleted;
use Modules\Saas\Support\ProvisioningStatus;
use Modules\Saas\Support\ProvisioningWorkflow;
use Modules\Branch\Models\Branch;
use Modules\Branch\Traits\HasBranch;
use Modules\Support\Eloquent\Model;

/**
 * Defence in depth. Every provisioning route sits behind `can:admin.saas.*`,
 * which a tenant is denied, so this is not reachable by a restaurant today —
 * but the model itself returned every tenant's runs to any authenticated
 * caller, and that should not be one route registration away from mattering.
 *
 * Unaffected by the scopes: queue jobs (no authenticated user) and platform
 * admins (HasBranch exempts super admins with no tenant or branch).
 */
class SaasProvisioningRun extends Model
{
    use HasBranch;

    protected $fillable = [
        'uuid',
        'tenant_id',
        'branch_id',
        'status',
        'progress',
        'current_step',
        'steps',
        'metadata',
        'error',
        'started_at',
        'completed_at',
        'failed_at',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function markStep(string $key, string $status, ?string $message = null): void
    {
        $steps = $this->steps ?? [];
        $previous = $steps[$key] ?? [];
        $definition = ProvisioningWorkflow::definitions()[$key] ?? null;
        $startedAt = $previous['started_at'] ?? now()->toIso8601String();
        $logs = $previous['logs'] ?? [];

        if ($message) {
            $logs[] = [
                'level' => $status === 'failed' ? 'error' : 'info',
                'message' => $message,
                'at' => now()->toIso8601String(),
            ];
        }

        $completedAt = $status === 'completed' ? now()->toIso8601String() : ($previous['completed_at'] ?? null);
        $duration = $completedAt
            ? Carbon::parse($startedAt)->diffInMilliseconds(Carbon::parse($completedAt))
            : ($previous['duration_ms'] ?? null);

        $steps[$key] = [
            'status' => $status,
            'state' => $previous['state'] ?? $definition?->state ?? strtoupper($key),
            'message' => $message,
            'started_at' => $status === 'processing' ? $startedAt : ($previous['started_at'] ?? $startedAt),
            'completed_at' => $completedAt,
            'duration_ms' => $duration,
            'retry_count' => $previous['retry_count'] ?? 0,
            'failure_reason' => $status === 'failed' ? $message : null,
            'logs' => $logs,
            'updated_at' => now()->toIso8601String(),
        ];

        $definitions = ProvisioningWorkflow::definitions();
        $completed = collect($steps)->map(
            fn (array $step, string $stepKey) => ($step['status'] ?? null) === 'completed'
                ? (($definitions[$stepKey] ?? null)?->weight ?? 1)
                : 0
        )->sum();
        $total = max(
            1,
            collect($steps)
                ->map(fn (array $step, string $stepKey) => ($definitions[$stepKey] ?? null)?->weight ?? 1)
                ->sum()
        );

        $this->forceFill([
            'steps' => $steps,
            'current_step' => $key,
            'progress' => min(99, (int) floor(($completed / $total) * 100)),
            'status' => $status === 'failed' ? 'failed' : 'processing',
            'started_at' => $this->started_at ?? now(),
            'failed_at' => $status === 'failed' ? now() : $this->failed_at,
            'error' => $status === 'failed' ? $message : $this->error,
        ])->save();

        match ($status) {
            'processing' => event(new ProvisioningStepStarted($this->fresh(), $key)),
            'completed' => event(new ProvisioningStepCompleted($this->fresh(), $key)),
            'failed' => event(new ProvisioningStepFailed($this->fresh(), $key, (string) $message)),
            default => null,
        };
    }

    public function complete(): void
    {
        $this->markStep('completed', 'completed');
        $this->forceFill([
            'status' => 'completed',
            'progress' => 100,
            'current_step' => 'completed',
            'completed_at' => now(),
            'error' => null,
        ])->save();

        event(new TenantProvisioningCompleted($this->fresh()));
    }

    public function cancel(string $reason = 'Cancelled by admin.'): void
    {
        $this->forceFill([
            'status' => 'cancelled',
            'current_step' => 'cancelled',
            'error' => $reason,
            'failed_at' => now(),
        ])->save();

        event(new TenantProvisioningCancelled($this->fresh()));
    }

    public function markPartial(string $reason): void
    {
        $this->forceFill([
            'status' => 'partially_completed',
            'current_step' => 'partially_completed',
            'error' => $reason,
            'failed_at' => now(),
        ])->save();
    }

    public function incrementStepRetry(string $key): void
    {
        $steps = $this->steps ?? [];
        $steps[$key]['retry_count'] = (int) ($steps[$key]['retry_count'] ?? 0) + 1;
        $steps[$key]['status'] = 'pending';
        $steps[$key]['failure_reason'] = null;
        $steps[$key]['logs'][] = [
            'level' => 'info',
            'message' => 'Step queued for retry.',
            'at' => now()->toIso8601String(),
        ];

        $this->forceFill([
            'steps' => $steps,
            'status' => 'processing',
            'failed_at' => null,
            'error' => null,
        ])->save();
    }

    public function state(): string
    {
        return match ($this->status) {
            'completed' => ProvisioningStatus::COMPLETED,
            'failed' => ProvisioningStatus::FAILED,
            'cancelled' => ProvisioningStatus::CANCELLED,
            'partially_completed' => ProvisioningStatus::PARTIALLY_COMPLETED,
            default => $this->steps[$this->current_step]['state'] ?? ProvisioningStatus::PENDING,
        };
    }

    protected function casts(): array
    {
        return [
            'steps' => 'array',
            'metadata' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }
}
