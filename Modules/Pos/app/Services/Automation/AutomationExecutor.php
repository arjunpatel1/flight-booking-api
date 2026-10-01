<?php

namespace Modules\Pos\Services\Automation;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Modules\Core\Automation\AutomationExecutionState;
use Modules\Core\Events\PlatformEvent;
use Modules\Core\Models\AutomationExecution;
use Modules\Pos\Events\AutomationStreamEvent;
use Modules\Pos\Services\ManagerApproval\PosManagerApprovalService;

/**
 * RAE v2 — controlled, idempotent, auditable automation execution.
 *
 * Re-evaluates the automation live (rejecting stale ones), enforces the
 * ActionPolicy/permission decision from the engine, executes only reversible
 * (safe) actions inside a transaction with idempotency-key dedup, records the
 * full lifecycle, and supports explicit rollback. Risky actions are never
 * auto-executed here — they require the existing Manager Approval flow.
 */
class AutomationExecutor
{
    /** Maps risky automation actions to the existing manager-approval actions. */
    private const APPROVAL_ACTION_MAP = [
        'auto_cancel' => 'order.cancel',
        'auto_refund' => 'order.refund',
    ];

    public function __construct(
        private readonly RestaurantAutomationService $automation,
        private readonly AutomationActionRunner $runner,
        private readonly PosManagerApprovalService $approvals,
    ) {
    }

    /**
     * @return array{ok:bool,code:int,body:array<string,mixed>}
     */
    public function execute(string $automationId, ?int $branchId, ?string $idempotencyKey, ?string $deviceId): array
    {
        $userId = auth()->id();

        // Idempotent replay — same key returns the prior execution.
        if ($idempotencyKey && ($prior = AutomationExecution::where('idempotency_key', $idempotencyKey)->first())) {
            return $this->ok($prior->toPayload());
        }

        $decision = $this->resolveDecision($automationId, $branchId);
        if ($decision === null || ($decision['state'] ?? 'inactive') === 'inactive') {
            return $this->reject(409, 'expired', 'Automation is no longer applicable (stale).');
        }

        if (($decision['action_policy']['allowed'] ?? false) !== true) {
            return $this->reject(403, 'blocked', $decision['action_policy']['reason'] ?? 'Action not allowed.');
        }

        $actionKey = (string) ($decision['action']['key'] ?? '');
        $class = $this->runner->classify($actionKey);
        if ($class === 'approval_required') {
            return $this->reject(403, 'eligible', 'This automation requires manager approval and cannot be auto-executed.', [
                'requires_manager_approval' => true,
                'manager_approval' => $this->approvalMeta($actionKey, $decision, $branchId),
            ]);
        }
        if ($class !== 'safe') {
            return $this->reject(422, 'eligible', 'This automation is suggestion-only and is not executable.');
        }

        return $this->runSafe($decision, $actionKey, $branchId, $userId, $idempotencyKey, $deviceId);
    }

    /**
     * @param array<string,mixed> $decision
     * @return array{ok:bool,code:int,body:array<string,mixed>}
     */
    private function runSafe(array $decision, string $actionKey, ?int $branchId, ?int $userId, ?string $idemKey, ?string $deviceId): array
    {
        $startedAt = now();

        try {
            return DB::transaction(function () use ($decision, $actionKey, $branchId, $userId, $idemKey, $deviceId, $startedAt) {
                $execution = AutomationExecution::create([
                    'automation_id' => (string) $decision['id'],
                    'domain' => $decision['domain'] ?? null,
                    'trigger' => $decision['trigger'] ?? null,
                    'action_key' => $actionKey,
                    'state' => AutomationExecutionState::Executing,
                    'idempotency_key' => $idemKey,
                    'executor_id' => $userId,
                    'branch_id' => $branchId,
                    'device_id' => $deviceId,
                    'started_at' => $startedAt,
                ]);

                $outcome = $this->runner->run($actionKey, (array) ($decision['evidence'] ?? []), $userId, $branchId);
                $rollback = $outcome['rollback'] ?? null;

                $execution->update([
                    'state' => AutomationExecutionState::Completed,
                    'result' => $outcome['result'] ?? [],
                    'rollback_available' => $rollback !== null,
                    'rollback_payload' => $rollback,
                    'completed_at' => now(),
                    'duration_ms' => (int) $startedAt->diffInMilliseconds(now()),
                ]);

                $this->publish(PlatformEvent::AUTOMATION_COMPLETED, $execution);

                return $this->ok($execution->toPayload());
            });
        } catch (QueryException $e) {
            // Concurrent duplicate (unique idempotency_key) — only one wins.
            if ($idemKey && ($winner = AutomationExecution::where('idempotency_key', $idemKey)->first())) {
                return $this->ok($winner->toPayload());
            }

            return $this->fail($decision, $actionKey, $branchId, $userId, $deviceId, $e->getMessage());
        } catch (\Throwable $e) {
            return $this->fail($decision, $actionKey, $branchId, $userId, $deviceId, $e->getMessage());
        }
    }

    public function rollback(int $executionId): array
    {
        $execution = AutomationExecution::find($executionId);
        if (! $execution) {
            return $this->reject(404, 'expired', 'Execution not found.');
        }

        if (! $execution->rollback_available || $execution->state === AutomationExecutionState::RolledBack) {
            return $this->reject(422, $this->stateValue($execution), 'This execution cannot be rolled back.');
        }

        if (! $this->runner->rollback((array) $execution->rollback_payload)) {
            return $this->reject(422, $this->stateValue($execution), 'Rollback could not be applied.');
        }

        $execution->update([
            'state' => AutomationExecutionState::RolledBack,
            'rolled_back_at' => now(),
        ]);
        $this->publish(PlatformEvent::AUTOMATION_ROLLED_BACK, $execution);

        return $this->ok($execution->toPayload());
    }

    /**
     * @param array<string,mixed> $decision
     */
    private function fail(array $decision, string $actionKey, ?int $branchId, ?int $userId, ?string $deviceId, string $reason): array
    {
        $execution = AutomationExecution::create([
            'automation_id' => (string) ($decision['id'] ?? ''),
            'domain' => $decision['domain'] ?? null,
            'trigger' => $decision['trigger'] ?? null,
            'action_key' => $actionKey,
            'state' => AutomationExecutionState::Failed,
            'executor_id' => $userId,
            'branch_id' => $branchId,
            'device_id' => $deviceId,
            'failure_reason' => $reason,
            'started_at' => now(),
            'completed_at' => now(),
        ]);
        $this->publish(PlatformEvent::AUTOMATION_FAILED, $execution);

        return ['ok' => false, 'code' => 500, 'body' => $execution->toPayload()];
    }

    /**
     * Approval meta (managers, resource) for the existing manager-approval flow,
     * so the client can request approval before running a risky action. Null when
     * the action has no supported approval mapping or no resolvable order/branch.
     *
     * @param array<string,mixed> $decision
     * @return array<string,mixed>|null
     */
    private function approvalMeta(string $actionKey, array $decision, ?int $branchId): ?array
    {
        $approvalAction = self::APPROVAL_ACTION_MAP[$actionKey] ?? null;
        $orderId = $decision['evidence']['order_id'] ?? null;

        if ($approvalAction === null || $branchId === null || ! $orderId) {
            return null;
        }

        return $this->approvals->metaForAction($branchId, $approvalAction, 'order', (string) $orderId);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function resolveDecision(string $automationId, ?int $branchId): ?array
    {
        foreach (($this->automation->evaluate($branchId)['decisions'] ?? []) as $decision) {
            if ((string) ($decision['id'] ?? '') === $automationId) {
                return $decision;
            }
        }

        return null;
    }

    private function publish(string $eventName, AutomationExecution $execution): void
    {
        try {
            activity('restaurant_automation')
                ->event($eventName)
                ->withProperties($execution->toPayload())
                ->log('Automation '.$eventName);

            // Live automation stream via the unified event bus (branch channel).
            event(new AutomationStreamEvent(
                eventName: $eventName,
                entityId: $execution->id,
                branchId: $execution->branch_id,
                payload: $execution->toPayload(),
            ));
        } catch (\Throwable) {
            // Audit/event publishing must never break execution.
        }
    }

    private function stateValue(AutomationExecution $execution): string
    {
        return $execution->state instanceof AutomationExecutionState
            ? $execution->state->value
            : (string) $execution->state;
    }

    /**
     * @param array<string,mixed> $body
     */
    private function ok(array $body): array
    {
        return ['ok' => true, 'code' => 200, 'body' => $body];
    }

    /**
     * @param array<string,mixed> $extra
     */
    private function reject(int $code, string $state, string $reason, array $extra = []): array
    {
        return ['ok' => false, 'code' => $code, 'body' => ['state' => $state, 'reason' => $reason, ...$extra]];
    }
}
