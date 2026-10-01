<?php

namespace Modules\Pos\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\Controller;
use Modules\Pos\Http\Requests\Api\V1\SavePosRegisterRequest;
use Modules\Pos\Services\PosRegister\PosRegisterServiceInterface;
use Modules\Pos\Transformers\Api\V1\PosRegisterResource;
use Modules\Support\ApiResponse;

class PosRegisterController extends Controller
{
    /**
     * Create a new instance of PosRegisterController
     *
     * @param PosRegisterServiceInterface $service
     */
    public function __construct(protected PosRegisterServiceInterface $service)
    {
    }

    /**
     * This method retrieves and returns a list of PosRegister models.
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
            resource: PosRegisterResource::class,
            filters: $request->get('with_filters')
                ? $this->service->getStructureFilters()
                : null
        );
    }

    /**
     * This method retrieves and returns a single PosRegister model based on the provided identifier.
     *
     * @param int $id
     * @return JsonResponse
     */
    public function show(int $id): JsonResponse
    {
        return ApiResponse::success(
            body: new PosRegisterResource($this->service->show($id))
        );
    }

    /**
     * This method stores the provided data into storage for the PosRegister model.
     *
     * @param SavePosRegisterRequest $request
     * @return JsonResponse
     */
    public function store(SavePosRegisterRequest $request): JsonResponse
    {
        return ApiResponse::created(
            body: new PosRegisterResource($this->service->store($request->validated())),
            resource: $this->service->label()
        );
    }

    /**
     * This method updates the provided data for the PosRegister model.
     *
     * @param SavePosRegisterRequest $request
     * @param int $id
     * @return JsonResponse
     */
    public function update(SavePosRegisterRequest $request, int $id): JsonResponse
    {
        return ApiResponse::updated(
            body: new PosRegisterResource($this->service->update($id, $request->validated())),
            resource: $this->service->label()
        );
    }

    /**
     * This method deletes the PosRegister model based on the provided ids.
     *
     * @param string $ids
     * @return JsonResponse
     */
    public function destroy(string $ids): JsonResponse
    {
        return ApiResponse::destroyed(
            destroyed: $this->service->destroy($ids),
            resource: $this->service->label()
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
        return ApiResponse::success($this->service->getFormMeta($request->get('branch_id')));
    }
}
