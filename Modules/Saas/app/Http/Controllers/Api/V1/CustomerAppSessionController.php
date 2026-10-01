<?php

namespace Modules\Saas\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Modules\Saas\Exceptions\CustomerAppAuthorizationException;
use Modules\Saas\Models\CustomerAppRegistration;
use Modules\Saas\Services\CustomerApp\CustomerAppSessionService;
use Modules\Support\ApiResponse;
use Throwable;

class CustomerAppSessionController extends Controller
{
    public function __invoke(Request $request, CustomerAppSessionService $sessions): JsonResponse
    {
        $appUuid = trim((string) $request->input('app_uuid'));
        $packageId = trim((string) $request->input('package_id'));
        $platform = strtolower(trim((string) $request->input('platform')));
        $installationId = trim((string) $request->input('installation_id'));
        $manifest = $request->input('manifest');

        if (! Str::isUuid($appUuid)
            || ! Str::isUuid($installationId)
            || $packageId === ''
            || strlen($packageId) > 255
            || ! is_array($manifest)
            || ! in_array($platform, [CustomerAppRegistration::PLATFORM_ANDROID, CustomerAppRegistration::PLATFORM_IOS], true)) {
            return $this->unavailable();
        }

        try {
            $exchange = $sessions->exchange($manifest, $appUuid, $packageId, $platform, $installationId);

            return ApiResponse::success([
                'session_token' => $exchange['token'],
                'expires_at' => $exchange['session']->expires_at?->toIso8601String(),
            ]);
        } catch (CustomerAppAuthorizationException) {
            return $this->unavailable();
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::errors(null, 'Restaurant services are temporarily offline. Please retry shortly.', 503, [
                'code' => 'CUSTOMER_APP_TEMPORARILY_UNAVAILABLE',
            ]);
        }
    }

    private function unavailable(): JsonResponse
    {
        return ApiResponse::errors(null, 'Online customer ordering is not enabled for this restaurant plan.', 403, [
            'code' => 'CUSTOMER_APP_UNAVAILABLE',
        ]);
    }
}
