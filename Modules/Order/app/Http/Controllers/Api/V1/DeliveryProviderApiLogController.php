<?php

namespace Modules\Order\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\Controller;
use Modules\Order\Models\DeliveryProviderApiLog;
use Modules\Support\ApiResponse;

class DeliveryProviderApiLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:all,success,failed'],
            'operation' => ['nullable', 'string', 'max:80'],
            'environment' => ['nullable', 'in:all,sandbox,production'],
            'http_status' => ['nullable', 'integer', 'between:100,599'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);
        $query = DeliveryProviderApiLog::query()->latest();
        if (filled($data['operation'] ?? null)) $query->where('operation', $data['operation']);
        if (($data['environment'] ?? 'all') !== 'all') $query->where('environment', $data['environment']);
        if (filled($data['http_status'] ?? null)) $query->where('http_status', (int) $data['http_status']);
        if (filled($data['from'] ?? null)) $query->where('created_at', '>=', $data['from']);
        if (filled($data['to'] ?? null)) $query->where('created_at', '<=', $data['to']);
        if (filled($data['search'] ?? null)) {
            $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $data['search']).'%';
            $query->where(fn ($q) => $q->where('order_reference', 'like', $term)->orWhere('operation', 'like', $term)->orWhere('message', 'like', $term));
        }
        if (($data['status'] ?? 'all') !== 'all') $query->where('successful', $data['status'] === 'success');
        return ApiResponse::pagination($query->paginate(50));
    }

    public function clear(): JsonResponse
    {
        $count = DeliveryProviderApiLog::query()->delete();
        return ApiResponse::success(['deleted' => $count], 'Provider API logs cleared.');
    }
}
