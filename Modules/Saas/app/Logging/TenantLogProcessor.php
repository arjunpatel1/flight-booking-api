<?php

namespace Modules\Saas\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Modules\Saas\Support\TenantContext;

/**
 * Enriches every log record with tenant and request context (Phase 2, Module 8).
 *
 * Adds — and only ever adds — four keys to the log record's `extra`:
 *   tenant_id, tenant_slug, request_id, user_id.
 *
 * It reads context, never credentials. It touches no message body and no
 * existing context key, so it cannot change what any current log statement
 * emits — it only appends. Registered as a Monolog *tap* (see config/logging.php)
 * so it applies to every channel without editing a single `Log::` call.
 *
 * Deliberately defensive: resolving the tenant/request must never itself throw
 * inside the logger, so every lookup is guarded.
 */
class TenantLogProcessor implements ProcessorInterface
{
    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(extra: [...$record->extra, ...$this->context()]);
    }

    private function context(): array
    {
        $data = [
            'tenant_id' => null,
            'tenant_slug' => null,
            'request_id' => null,
            'user_id' => null,
        ];

        try {
            if (app()->bound(TenantContext::class)) {
                $tenant = app(TenantContext::class);
                $data['tenant_id'] = $tenant->id();
                // slug() may lazily query; guarded, and skipped if only id known
                // cheaply — acceptable for log volume, and often already loaded.
                $data['tenant_slug'] = $tenant->slug();
            }

            if (app()->bound('request')) {
                $request = app('request');
                $data['request_id'] = $request->headers->get('X-Request-Id')
                    ?? $request->attributes->get('request_id');
                $data['user_id'] = optional($request->user())->id;
            }
        } catch (\Throwable) {
            // Never let logging fail because context resolution did.
        }

        return array_filter($data, static fn ($v) => $v !== null);
    }
}
