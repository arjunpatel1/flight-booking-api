<?php

namespace Modules\ActivityLog\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\ActivityLog\Services\AuthenticationLog\AuthenticationLogServiceInterface;
use Modules\ActivityLog\Transformers\Api\V1\AuthenticationLogResource;
use Modules\Core\Http\Controllers\Controller;
use Modules\Support\ApiResponse;

class AuthenticationLogController extends Controller
{
    /**
     * Create a new instance of AuthenticationLogController
     *
     * @param AuthenticationLogServiceInterface $service
     */
    public function __construct(protected AuthenticationLogServiceInterface $service)
    {
    }

    /**
     * Display a listing of the resource.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        return ApiResponse::pagination(
            $this->service->get(
                filters: $request->get('filters', []),
                sorts: $request->get('sorts', []),
            ),
            AuthenticationLogResource::class
        );
    }

    /**
     * Show the specified resource.
     *
     * @param int $id
     * @return JsonResponse
     */
    public function show(int $id): JsonResponse
    {
        return ApiResponse::success(new AuthenticationLogResource($this->service->show($id)));
    }
}
