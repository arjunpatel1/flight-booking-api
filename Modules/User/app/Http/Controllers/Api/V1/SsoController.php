<?php

namespace Modules\User\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\Controller;
use Modules\Support\ApiResponse;
use Modules\User\Services\Auth\EnterpriseSsoService;
use Modules\User\Transformers\Api\V1\AuthResource;

class SsoController extends Controller
{
    public function __construct(private readonly EnterpriseSsoService $service)
    {
    }

    public function providers(): JsonResponse
    {
        return ApiResponse::success([
            'providers' => $this->service->providers(),
        ]);
    }

    public function redirect(Request $request, string $provider): RedirectResponse
    {
        $data = $request->validate([
            'redirect_uri' => ['required', 'url'],
        ]);

        return redirect()->away(
            $this->service->redirectUrl($provider, $data['redirect_uri'], $request->headers->get('origin'))
        );
    }

    public function callback(Request $request, string $provider): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string'],
            'state' => ['required', 'string'],
        ]);

        return redirect()->away(
            $this->service->callback($provider, $data['code'], $data['state'])
        );
    }

    public function complete(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
        ]);

        $result = $this->service->complete($data['token']);

        return ApiResponse::success([
            'user' => new AuthResource($result['user']),
            'token' => $result['token'],
            'expires_at' => $result['expires_at'] ?? null,
        ]);
    }
}
