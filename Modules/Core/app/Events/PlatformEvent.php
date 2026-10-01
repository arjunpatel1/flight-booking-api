<?php

namespace Modules\Core\Events;

use Illuminate\Support\Str;

/**
 * Canonical platform event contract.
 *
 * Single source of truth for (a) the event-name registry — no module may invent
 * its own name — and (b) the standard envelope every broadcast carries so that
 * Backend, Reverb, Flutter, Vue, Notifications, Voice and the Print Agent all
 * speak one event language.
 *
 * Wire names are the existing dot.case broadcast names (kept for backward compat
 * with current listeners). The envelope is added ADDITIVELY alongside any legacy
 * payload keys during migration.
 */
final class PlatformEvent
{
    public const VERSION = 1;
    public const SOURCE_BACKEND = 'backend';

    // ── Event-name registry (canonical) ──────────────────────────────────
    public const ORDER_CREATED = 'order.created';
    public const ORDER_UPDATED = 'order.updated';
    public const ORDER_CANCELLED = 'order.cancelled';
    public const ORDER_PAID = 'order.paid';

    public const TABLE_STATUS_UPDATED = 'table.status.updated';

    public const PRINT_JOB_CREATED = 'print.job.created';

    public const NOTIFICATION_CREATED = 'notification.created';

    public const VOICE_ANNOUNCEMENT_CREATED = 'voice.announcement.triggered';

    public const AUTOMATION_EXECUTED = 'automation.executed';
    public const AUTOMATION_COMPLETED = 'automation.completed';
    public const AUTOMATION_FAILED = 'automation.failed';
    public const AUTOMATION_ROLLED_BACK = 'automation.rolled_back';

    /**
     * All registered canonical event names (for validation / observability).
     *
     * @return list<string>
     */
    public static function names(): array
    {
        return [
            self::ORDER_CREATED,
            self::ORDER_UPDATED,
            self::ORDER_CANCELLED,
            self::ORDER_PAID,
            self::TABLE_STATUS_UPDATED,
            self::PRINT_JOB_CREATED,
            self::NOTIFICATION_CREATED,
            self::VOICE_ANNOUNCEMENT_CREATED,
            self::AUTOMATION_EXECUTED,
            self::AUTOMATION_COMPLETED,
            self::AUTOMATION_FAILED,
            self::AUTOMATION_ROLLED_BACK,
        ];
    }

    /**
     * Build the canonical envelope for a broadcast payload.
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public static function envelope(
        string $eventName,
        string $entity,
        int|string|null $entityId,
        ?int $branchId,
        array $payload = [],
        ?string $correlationId = null,
    ): array {
        return [
            'event_id' => (string) Str::uuid(),
            'correlation_id' => $correlationId ?? self::resolveCorrelationId(),
            'event_name' => $eventName,
            'entity' => $entity,
            'entity_id' => $entityId,
            'branch_id' => $branchId,
            'tenant_id' => self::resolveTenantId(),
            'timestamp' => now()->toISOString(),
            'version' => self::VERSION,
            'source' => self::SOURCE_BACKEND,
            'payload' => $payload,
        ];
    }

    /**
     * Best-effort correlation id: reuse the inbound request header when present
     * (so an action and its downstream events share a trace), else a fresh uuid.
     */
    private static function resolveCorrelationId(): string
    {
        $header = request()?->header('X-Correlation-Id') ?? request()?->header('X-Request-Id');

        return is_string($header) && $header !== '' ? $header : (string) Str::uuid();
    }

    private static function resolveTenantId(): int|string|null
    {
        $user = auth()->user();

        return $user?->tenant_id ?? null;
    }
}
