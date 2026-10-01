<?php

namespace Modules\Aggregator\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Aggregator\Models\PartnerApiRequestLog;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class AuditPartnerRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        $started = hrtime(true);
        $response = null;
        try {
            $response = $next($request);

            return $response;
        } finally {
            $context = $request->attributes->get('partner_context');
            try {
                PartnerApiRequestLog::query()->create([
                    'request_id' => $request->attributes->get('request_id'),
                    'partner_id' => $context?->partner->id,
                    'credential_id' => $context?->credential->id,
                    'tenant_id' => $context?->tenantId(),
                    'branch_id' => $request->attributes->get('partner_branch_id'),
                    'method' => strtoupper($request->method()),
                    'path' => '/'.$request->path(),
                    'idempotency_hash' => $request->hasHeader('Idempotency-Key')
                        ? hash('sha256', (string) $request->header('Idempotency-Key')) : null,
                    'external_order_id' => mb_substr((string) $request->header('X-External-Order-Id', ''), 0, 160) ?: null,
                    'response_status' => $response?->getStatusCode() ?? 500,
                    'ip_address' => $request->ip(),
                    'user_agent' => mb_substr((string) $request->userAgent(), 0, 500),
                    'duration_ms' => max(0, (int) round((hrtime(true) - $started) / 1_000_000)),
                    'created_at' => now(),
                ]);
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }
}
