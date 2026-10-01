<?php

namespace Modules\Saas\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Modules\Saas\Exceptions\CustomerAppAuthorizationException;
use Modules\Saas\Models\CustomerAppRegistration;
use Modules\Saas\Services\CustomerApp\CustomerAppAuthorizationService;
use Modules\Saas\Services\CustomerApp\CustomerAppContentService;
use Modules\Saas\Services\CustomerApp\CustomerAppManifestService;
use Modules\Support\ApiResponse;
use Throwable;

class CustomerAppBootstrapController extends Controller
{
    public function __invoke(
        Request $request,
        CustomerAppAuthorizationService $authorization,
        CustomerAppContentService $content,
        CustomerAppManifestService $manifests,
    ): JsonResponse {
        $appUuid = trim((string) $request->input('app_uuid'));
        $packageId = trim((string) $request->input('package_id'));
        $platform = strtolower(trim((string) $request->input('platform')));

        if (! Str::isUuid($appUuid)
            || $packageId === ''
            || strlen($packageId) > 255
            || ! in_array($platform, [CustomerAppRegistration::PLATFORM_ANDROID, CustomerAppRegistration::PLATFORM_IOS], true)) {
            return $this->unavailable();
        }

        try {
            $registration = $authorization->resolve($appUuid, $packageId, $platform);
            $registration->loadMissing('tenant');
            $envelope = $manifests->issue($registration);

            return ApiResponse::success([
                'application' => [
                    'uuid' => $registration->uuid,
                    'display_name' => $registration->display_name,
                    'package_id' => $registration->package_id,
                    'platform' => $registration->platform,
                    'branding_revision' => (int) $registration->branding_revision,
                ],
                'manifest' => $envelope,
                'runtime' => $content->runtimePayload($registration->tenant),
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
