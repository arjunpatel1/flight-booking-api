<?php

namespace Modules\Saas\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Modules\Core\Http\Controllers\Controller;
use Modules\Saas\Services\Launch\LaunchReadinessService;
use Modules\Support\ApiResponse;

/**
 * Launch certification. Read-only: it reports what is ready and what is not,
 * and never changes anything. Fixing is done on the screen each check points at.
 */
class SaasLaunchReadinessController extends Controller
{
    public function __construct(private readonly LaunchReadinessService $readiness)
    {
    }

    public function index(): JsonResponse
    {
        return ApiResponse::success($this->readiness->report());
    }
}
