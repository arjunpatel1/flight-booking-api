<?php

namespace Modules\Saas\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Modules\Core\Http\Controllers\Controller;
use Modules\Saas\Services\CustomerSuccess\CustomerSuccessService;
use Modules\Saas\Services\Operations\OperationsCenterService;
use Modules\Saas\Services\Operations\SaasAlertStateService;
use Modules\Saas\Services\Operations\SaasRealtimeIncidentService;
use Modules\Saas\Services\Provisioning\SaasHealthService;
use Modules\Saas\Services\Provisioning\SaasServerAutomationService;
use Modules\Support\ApiResponse;

class SaasOperationsCenterController extends Controller
{
    public function index(
        OperationsCenterService $operations,
        SaasHealthService $tenantHealth,
        SaasServerAutomationService $serverAutomation,
        CustomerSuccessService $customerSuccess,
        SaasRealtimeIncidentService $realtimeIncidents,
        SaasAlertStateService $alertStates
    ): JsonResponse {
        return ApiResponse::success(
            $operations->dashboard($tenantHealth, $serverAutomation, $customerSuccess, $realtimeIncidents, $alertStates)
        );
    }
}
