<?php

namespace Modules\Pos\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\Controller;
use Modules\Pos\Http\Requests\Api\V1\SavePosCashMovementRequest;
use Modules\Pos\Services\PosCashMovement\PosCashMovementServiceInterface;
use Modules\Pos\Transformers\Api\V1\PosCashMovementResource;
use Modules\Pos\Transformers\Api\V1\PosCashMovementShowResource;
use Modules\Support\ApiResponse;
use Throwable;

class PosCashMovementController extends Controller
{
    /**
     * Create a new instance of PosCashMovementController
     *
     * @param PosCashMovementServiceInterface $service
     */
    public function __construct(protected PosCashMovementServiceInterface $service)
    {
    }

    /**
     * This method retrieves and returns a list of PosCashMovement models.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $sessionId = $request->get('session_id');

        return ApiResponse::pagination(
            paginator: $this->service->get(
                filters: [
                    ...$request->get('filters', []),
                    "pos_session_id" => $sessionId
                ],
                sorts: $request->get('sorts', []),
            ),
            resource: PosCashMovementResource::class,
            filters: $request->get('with_filters')
                ? $this->service->getStructureFilters(
                    auth()->user()->assignedToBranch()
                        ? auth()->user()->branch_id
                        : $request->get('filters')['branch_id'] ?? null,
                    $sessionId
                )
                : null
        );
    }

    /**
     * This method stores the provided data into storage for the PosCashMovement model.
     *
     * @param SavePosCashMovementRequest $request
     * @return JsonResponse
     * @throws Throwable
     */
    public function store(SavePosCashMovementRequest $request): JsonResponse
    {
        $this->service->store($request->validated());

        return ApiResponse::created(resource: $this->service->label());
    }

    /**
     * This method retrieves and returns a single PosCashMovement model based on the provided identifier.
     *
     * @param int $id
     * @return JsonResponse
     */
    public function show(int $id): JsonResponse
    {
        return ApiResponse::success(
            body: new PosCashMovementShowResource($this->service->show($id))
        );
    }
}
