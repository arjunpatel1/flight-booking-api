<?php

namespace Modules\Aggregator\Support;

use Illuminate\Http\JsonResponse;

final class PartnerApiResponse
{
    public static function success(mixed $data = null, int $status = 200, array $meta = []): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $data,
            'meta' => (object) $meta,
            'request_id' => request()->attributes->get('request_id') ?? request()->header('X-Request-Id'),
        ], $status)->withHeaders(['Cache-Control' => 'no-store, private', 'Pragma' => 'no-cache']);
    }

    public static function error(string $code, string $message, int $status, array $details = []): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error' => ['code' => $code, 'message' => $message, 'details' => (object) $details],
            'request_id' => request()->attributes->get('request_id') ?? request()->header('X-Request-Id'),
        ], $status)->withHeaders(['Cache-Control' => 'no-store, private', 'Pragma' => 'no-cache']);
    }
}
