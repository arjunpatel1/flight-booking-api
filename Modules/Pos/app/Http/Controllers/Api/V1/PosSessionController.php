<?php

namespace Modules\Pos\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\Controller;
use Modules\Pos\Http\Requests\Api\V1\CloseSessionRequest;
use Modules\Pos\Http\Requests\Api\V1\OpenSessionRequest;
use Modules\Pos\Services\PosSession\PosSessionServiceInterface;
use Modules\Pos\Transformers\Api\V1\PosSessionResource;
use Modules\Pos\Transformers\Api\V1\PosSessionShowResource;
use Modules\Support\ApiResponse;
use Throwable;

class PosSessionController extends Controller
{
    /**
     * Create a new instance of PosSessionController
     *
     * @param PosSessionServiceInterface $service
     */
    public function __construct(protected PosSessionServiceInterface $service)
    {
    }

    /**
     * This method retrieves and returns a list of PosSession models.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        return ApiResponse::pagination(
            paginator: $this->service->get(
                filters: $request->get('filters', []),
                sorts: $request->get('sorts', []),
            ),
            resource: PosSessionResource::class,
            filters: $request->get('with_filters')
                ? $this->service->getStructureFilters(
                    auth()->user()->assignedToBranch()
                        ? auth()->user()->branch_id
                        : $request->get('filters')['branch_id'] ?? null
                )
                : null
        );
    }

    /**
     * This method retrieves and returns a single PosSession model based on the provided identifier.
     *
     * @param int $id
     * @return JsonResponse
     */
    public function show(int $id): JsonResponse
    {
        return ApiResponse::success(
            body: new PosSessionShowResource($this->service->show($id))
        );
    }

    /**
     * Open pos sessions
     *
     * @param OpenSessionRequest $request
     * @return JsonResponse
     * @throws Throwable
     */
    public function open(OpenSessionRequest $request): JsonResponse
    {
        return ApiResponse::success(
            body: new PosSessionResource($this->service->open($request->validated())),
            message: __("pos::messages.success_opened_session")
        );
    }

    /**
     * Close pos session
     *
     * @param CloseSessionRequest $request
     * @param int $id
     * @return JsonResponse
     * @throws Throwable
     */
    public function close(CloseSessionRequest $request, int $id): JsonResponse
    {
        return ApiResponse::success(
            body: new PosSessionResource($this->service->close($id, $request->validated())),
            message: __("pos::messages.success_closed_session")
        );
    }

    /**
     * Get form meta
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getFormMeta(Request $request): JsonResponse
    {
        return ApiResponse::success(
            $this->service->getFormMeta(
                branchId: auth()->user()->assignedToBranch()
                    ? auth()->user()->branch_id
                    : $request->get('branch_id')
            )
        );
    }
}
