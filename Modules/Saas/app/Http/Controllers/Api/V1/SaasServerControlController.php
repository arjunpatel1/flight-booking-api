<?php

namespace Modules\Saas\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Modules\Core\Http\Controllers\Controller;
use Modules\Saas\Http\Requests\Api\V1\ConfigureApacheSslRequest;
use Modules\Saas\Services\Provisioning\SaasServerAutomationService;
use Modules\Support\ApiResponse;

class SaasServerControlController extends Controller
{
    public function health(SaasServerAutomationService $service): JsonResponse
    {
        return ApiResponse::success($service->health());
    }

    public function webserverSsl(ConfigureApacheSslRequest $request, SaasServerAutomationService $service): JsonResponse
    {
        $result = $service->configureWebServerSsl($request->validated());
        $label = ucfirst($result['web_server'] ?? 'server');

        return $result['successful']
            ? ApiResponse::success($result, $result['applied'] ? "{$label} and SSL configuration applied." : "{$label} and SSL preview generated.")
            : ApiResponse::errors($result, "{$label} and SSL automation failed.", 422);
    }
}
