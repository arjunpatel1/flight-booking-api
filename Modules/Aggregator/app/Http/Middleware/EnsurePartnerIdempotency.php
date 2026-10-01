<?php

namespace Modules\Aggregator\Http\Middleware;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Modules\Aggregator\Models\PartnerApiIdempotency;
use Modules\Aggregator\Support\PartnerApiResponse;
use Modules\Aggregator\Support\PartnerContext;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class EnsurePartnerIdempotency
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = trim((string) $request->header('Idempotency-Key'));
        if (! preg_match('/^[A-Za-z0-9._:-]{8,160}$/', $key)) {
            return PartnerApiResponse::error('IDEMPOTENCY_KEY_REQUIRED', 'A stable Idempotency-Key (8-160 safe characters) is required.', 400);
        }
        /** @var PartnerContext|null $context */
        $context = $request->attributes->get('partner_context');
        if (! $context) {
            return PartnerApiResponse::error('AUTHENTICATION_FAILED', 'Partner authentication failed.', 401);
        }
        $keyHash = hash('sha256', $key);
        $requestHash = hash('sha256', implode("\n", [strtoupper($request->method()), $request->getRequestUri(), $request->getContent()]));
        $lockSeconds = max(30, (int) config('aggregator.partner.idempotency_lock_seconds', 120));
        try {
            $record = PartnerApiIdempotency::query()->firstOrCreate(
                ['credential_id' => $context->credential->id, 'key_hash' => $keyHash],
                ['request_hash' => $requestHash, 'status' => 'processing', 'locked_until' => now()->addSeconds($lockSeconds)],
            );
        } catch (QueryException $exception) {
            // Two workers can both observe a missing key before the unique
            // index selects the winner. Treat the loser as a replay instead
            // of leaking a database exception as a 500 response.
            $record = PartnerApiIdempotency::query()
                ->where('credential_id', $context->credential->id)
                ->where('key_hash', $keyHash)
                ->first();
            if (! $record) {
                throw $exception;
            }
        }
        if (! hash_equals((string) $record->request_hash, $requestHash)) {
            return PartnerApiResponse::error('IDEMPOTENCY_CONFLICT', 'This Idempotency-Key was already used with a different request.', 409);
        }
        if (! $record->wasRecentlyCreated) {
            if ($record->status === 'completed' && $record->response_code && $record->response_body !== null) {
                return response($record->response_body, $record->response_code, [
                    'Content-Type' => 'application/json', 'Cache-Control' => 'no-store, private', 'X-Idempotent-Replay' => 'true',
                ]);
            }
            if ($record->locked_until?->isFuture()) {
                return PartnerApiResponse::error('REQUEST_IN_PROGRESS', 'A request with this Idempotency-Key is still processing.', 409);
            }
            $claimed = PartnerApiIdempotency::query()
                ->whereKey($record->id)
                ->where('status', '!=', 'completed')
                ->where(function ($query) {
                    $query->whereNull('locked_until')->orWhere('locked_until', '<=', now());
                })
                ->update(['status' => 'processing', 'locked_until' => now()->addSeconds($lockSeconds)]);
            if ($claimed !== 1) {
                return PartnerApiResponse::error('REQUEST_IN_PROGRESS', 'A request with this Idempotency-Key is still processing.', 409);
            }
        }

        try {
            $response = $next($request);
            if ($response->getStatusCode() < 500) {
                $record->update([
                    'status' => 'completed', 'response_code' => $response->getStatusCode(),
                    'response_body' => $response->getContent(), 'locked_until' => null, 'completed_at' => now(),
                ]);
            } else {
                $record->delete();
            }

            return $response;
        } catch (Throwable $exception) {
            $record->delete();
            throw $exception;
        }
    }
}
