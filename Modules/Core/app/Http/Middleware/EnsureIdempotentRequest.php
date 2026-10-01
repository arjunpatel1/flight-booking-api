<?php

namespace Modules\Core\Http\Middleware;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class EnsureIdempotentRequest
{
    public function handle(Request $request, Closure $next, string $requireKey = 'optional'): Response
    {
        $idempotencyKey = trim((string) $request->header('Idempotency-Key'));

        if ($idempotencyKey === '') {
            if ($requireKey === 'required') {
                return response()->json([
                    'message' => __('core::errors.idempotency_key_required'),
                    'errors' => [
                        'idempotency_key' => [__('core::errors.idempotency_key_required')],
                    ],
                ], 422);
            }

            return $next($request);
        }

        $keyHash = hash('sha256', implode('|', [
            $request->user()?->id ?: 'guest',
            $request->method(),
            $request->path(),
            $idempotencyKey,
        ]));
        $requestHash = hash('sha256', json_encode($request->all(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');

        $record = DB::table('idempotency_keys')->where('key_hash', $keyHash)->first();

        if ($record) {
            if (! hash_equals($record->request_hash, $requestHash)) {
                return response()->json([
                    'message' => __('core::errors.idempotency_key_reused'),
                ], 409);
            }

            if ($record->status === 'completed' && $record->response_code && $record->response_body !== null) {
                return new JsonResponse(
                    data: json_decode($record->response_body, true),
                    status: (int) $record->response_code,
                    headers: ['X-NexDine-Idempotent-Replay' => '1']
                );
            }

            if ($record->locked_until && now()->lessThan($record->locked_until)) {
                return response()->json([
                    'message' => __('core::errors.idempotency_request_processing'),
                ], 409);
            }

            $claimed = DB::table('idempotency_keys')
                ->where('key_hash', $keyHash)
                ->where('request_hash', $requestHash)
                ->where('status', 'processing')
                ->where(function ($query) {
                    $query->whereNull('locked_until')
                        ->orWhere('locked_until', '<=', now());
                })
                ->update([
                    'locked_until' => now()->addMinutes(5),
                    'updated_at' => now(),
                ]);

            if ($claimed !== 1) {
                return response()->json([
                    'message' => __('core::errors.idempotency_request_processing'),
                ], 409);
            }
        } else {
            try {
                DB::table('idempotency_keys')->insert([
                    'key_hash' => $keyHash,
                    'user_id' => $request->user()?->id,
                    'method' => $request->method(),
                    'route' => $request->path(),
                    'request_hash' => $requestHash,
                    'status' => 'processing',
                    'locked_until' => now()->addMinutes(5),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } catch (QueryException) {
                return response()->json([
                    'message' => __('core::errors.idempotency_request_processing'),
                ], 409);
            }
        }

        try {
            $response = $next($request);
        } catch (\Throwable $exception) {
            // A thrown request left the key locked for the full five minutes,
            // so every retry answered "already being processed" and the caller
            // never saw the real error. Release the lock and surface the cause.
            $this->releaseLock($keyHash);

            throw $exception;
        }

        if ($response instanceof JsonResponse && $response->getStatusCode() < 500) {
            DB::table('idempotency_keys')
                ->where('key_hash', $keyHash)
                ->update([
                    'status' => 'completed',
                    'response_code' => $response->getStatusCode(),
                    'response_body' => $response->getContent(),
                    'locked_until' => null,
                    'completed_at' => now(),
                    'updated_at' => now(),
                ]);

            return $response;
        }

        // Server errors and non-JSON responses are not replayable results.
        // Holding the lock here blocks a legitimate retry for five minutes.
        $this->releaseLock($keyHash);

        return $response;
    }

    /**
     * Free the key for an immediate retry.
     *
     * The row is kept (rather than deleted) so the attempt stays visible for
     * auditing; clearing locked_until is what lets the next request re-claim it.
     */
    private function releaseLock(string $keyHash): void
    {
        DB::table('idempotency_keys')
            ->where('key_hash', $keyHash)
            ->where('status', 'processing')
            ->update([
                'locked_until' => null,
                'updated_at' => now(),
            ]);
    }
}
