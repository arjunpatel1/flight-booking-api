<?php

namespace Modules\Saas\Services\Operations;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Modules\Saas\Models\SaasAlertState;

class SaasAlertStateService
{
    public function decorate(Collection $alerts): array
    {
        if (! $this->isAvailable()) {
            return $alerts
                ->map(fn (array $alert) => [...$alert, 'fingerprint' => $this->fingerprint($alert), 'status' => 'open'])
                ->values()
                ->all();
        }

        $fingerprints = $alerts->map(fn (array $alert) => $this->fingerprint($alert));
        $now = now();

        if ($this->supportsObservationHistory()) {
            SaasAlertState::query()
                ->whereIn('status', ['open', 'acknowledged'])
                ->when($fingerprints->isNotEmpty(), fn ($query) => $query->whereNotIn('fingerprint', $fingerprints))
                ->update(['status' => 'resolved', 'resolved_at' => $now, 'note' => 'Automatically resolved after the health signal recovered.']);

            $alerts->each(function (array $alert) use ($now): void {
            $fingerprint = $this->fingerprint($alert);
            $state = SaasAlertState::query()->firstOrNew(['fingerprint' => $fingerprint]);
            $reopened = $state->exists && $state->status === 'resolved';
            $state->fill([
                'tenant_id' => $alert['tenant_id'] ?? $alert['tenantId'] ?? null,
                'type' => $alert['type'] ?? 'platform_alert',
                'severity' => $alert['severity'] ?? 'warning',
                'title' => $alert['title'] ?? 'Platform alert',
                'message' => $alert['message'] ?? null,
                'status' => $reopened ? 'open' : ($state->status ?: 'open'),
                'first_seen_at' => $state->first_seen_at ?: $now,
                'last_seen_at' => $now,
                'occurrences' => $reopened ? ((int) $state->occurrences + 1) : max(1, (int) $state->occurrences),
                'resolved_at' => $reopened ? null : $state->resolved_at,
                'resolved_by' => $reopened ? null : $state->resolved_by,
            ])->save();
            });
        }
        $states = SaasAlertState::query()
            ->whereIn('fingerprint', $fingerprints)
            ->get()
            ->keyBy('fingerprint');

        return $alerts
            ->map(function (array $alert) use ($states): array {
                $fingerprint = $this->fingerprint($alert);
                $state = $states->get($fingerprint);

                return [
                    ...$alert,
                    'fingerprint' => $fingerprint,
                    'status' => $state?->status ?? 'open',
                    'assigned_to' => $state?->assigned_to,
                    'acknowledged_at' => $state?->acknowledged_at?->toIso8601String(),
                    'resolved_at' => $state?->resolved_at?->toIso8601String(),
                ];
            })
            ->reject(fn (array $alert) => $alert['status'] === 'resolved')
            ->values()
            ->all();
    }

    public function history(int $limit = 30): array
    {
        if (! $this->isAvailable()) {
            return [];
        }

        $supportsHistory = $this->supportsObservationHistory();

        return SaasAlertState::query()
            ->with(['tenant:id,name', 'assignee:id,name'])
            ->latest($supportsHistory ? 'last_seen_at' : 'updated_at')
            ->limit(min(100, max(1, $limit)))
            ->get()
            ->map(fn (SaasAlertState $state) => [
                'fingerprint' => $state->fingerprint,
                'tenant_id' => $state->tenant_id,
                'tenant_name' => $state->tenant?->name,
                'type' => $state->type,
                'severity' => $state->severity,
                'title' => $state->title,
                'message' => $state->message,
                'status' => $state->status,
                'assigned_to' => $state->assignee?->name,
                'note' => $state->note,
                'first_seen_at' => $state->first_seen_at?->toIso8601String(),
                'last_seen_at' => $state->last_seen_at?->toIso8601String() ?? $state->updated_at?->toIso8601String(),
                'resolved_at' => $state->resolved_at?->toIso8601String(),
                'occurrences' => $state->occurrences,
            ])
            ->all();
    }

    public function acknowledge(string $fingerprint, ?int $actorId, ?string $note = null): SaasAlertState
    {
        return $this->storeState($fingerprint, $actorId, $note, 'acknowledged');
    }

    public function resolve(string $fingerprint, ?int $actorId, ?string $note = null): SaasAlertState
    {
        return $this->storeState($fingerprint, $actorId, $note, 'resolved');
    }

    public function assignTo(string $fingerprint, int $actorId, ?string $note = null): SaasAlertState
    {
        abort_unless($this->isAvailable(), 503, 'Alert state storage is not available. Run pending SaaS migrations first.');

        $state = SaasAlertState::query()->firstOrNew(['fingerprint' => $fingerprint]);
        $state->fill([
            'type' => $state->type ?: 'platform_alert',
            'status' => $state->status ?: 'open',
            'assigned_to' => $actorId,
            'note' => $note ?: $state->note,
        ])->save();

        return $state->refresh();
    }

    private function storeState(string $fingerprint, ?int $actorId, ?string $note, string $status): SaasAlertState
    {
        abort_unless($this->isAvailable(), 503, 'Alert state storage is not available. Run pending SaaS migrations first.');

        $state = SaasAlertState::query()->firstOrNew(['fingerprint' => $fingerprint]);
        $now = now();

        $state->fill([
            'type' => $state->type ?: 'platform_alert',
            'status' => $status,
            'acknowledged_by' => $status === 'acknowledged' ? $actorId : $state->acknowledged_by,
            'acknowledged_at' => $status === 'acknowledged' ? $now : $state->acknowledged_at,
            'resolved_by' => $status === 'resolved' ? $actorId : null,
            'resolved_at' => $status === 'resolved' ? $now : null,
            'note' => $note ?: $state->note,
        ])->save();

        return $state->refresh();
    }

    private function fingerprint(array $alert): string
    {
        $tenantId = $alert['tenant_id'] ?? $alert['tenantId'] ?? null;
        $type = $alert['type'] ?? 'platform_alert';
        $title = $alert['title'] ?? '';
        $key = $alert['key'] ?? $title;

        return hash('sha256', implode('|', [(string) $tenantId, (string) $type, (string) $key]));
    }

    private function isAvailable(): bool
    {
        static $available = null;

        return $available ??= Schema::hasTable('saas_alert_states');
    }

    private function supportsObservationHistory(): bool
    {
        static $available = null;

        return $available ??= $this->isAvailable() && Schema::hasColumns('saas_alert_states', [
            'severity', 'title', 'message', 'first_seen_at', 'last_seen_at', 'occurrences',
        ]);
    }
}
