<?php

namespace Modules\Pos\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\Controller;
use Modules\Pos\Services\OwnerCommandCenter\OwnerCommandCenterService;
use Modules\Support\ApiResponse;

class PosOwnerCommandCenterController extends Controller
{
    public function __construct(private readonly OwnerCommandCenterService $service)
    {
    }

    public function show(Request $request): JsonResponse
    {
        return ApiResponse::success($this->service->show(
            $request->integer('branch_id') ?: null
        ));
    }
}
