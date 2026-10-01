<?php

namespace Modules\User\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\Controller;
use Modules\Support\ApiResponse;
use Modules\User\Services\Auth\TenantHandoffService;
use Modules\User\Transformers\Api\V1\AuthResource;

class TenantHandoffController extends Controller
{
    public function complete(Request $request, TenantHandoffService $handoff): JsonResponse
    {
        $request->validate([
            'token' => ['required', 'string', 'max:160'],
        ]);

        $data = $handoff->complete((string) $request->input('token'));

        return ApiResponse::success([
            'user' => new AuthResource($data['user']),
            'token' => $data['token'],
            'expires_at' => $data['expires_at'] ?? null,
            'support_mode' => $data['support_mode'] ?? null,
        ]);
    }
}
