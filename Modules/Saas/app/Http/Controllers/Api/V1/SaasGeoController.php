<?php

namespace Modules\Saas\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Modules\Core\Http\Controllers\Controller;
use Modules\Saas\Services\Onboarding\GeoCatalog;
use Modules\Support\ApiResponse;

class SaasGeoController extends Controller
{
    public function __construct(private readonly GeoCatalog $geo) {}

    /** GET /api/v1/saas/geo/countries */
    public function countries(): JsonResponse
    {
        return ApiResponse::success($this->geo->countries());
    }

    /** GET /api/v1/saas/geo/countries/{code}/states */
    public function states(string $code): JsonResponse
    {
        return ApiResponse::success($this->geo->states($code));
    }
}
