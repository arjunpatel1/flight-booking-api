<?php

namespace Modules\Loyalty\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Modules\Loyalty\Http\Requests\Api\V1\SaveLoyaltyProgramRequest;
use Modules\Loyalty\Transformers\Api\V1\LoyaltyProgramResource;
use Modules\Loyalty\Services\LoyaltyProgram\LoyaltyProgramServiceInterface;
use Modules\Core\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Support\ApiResponse;

class LoyaltyProgramController extends Controller
{
    /**
     * Create a new instance of LoyaltyProgramController
     *
     * @param LoyaltyProgramServiceInterface $service
     */
    public function __construct(protected LoyaltyProgramServiceInterface $service)
    {
    }

    /**
     * This method retrieves and returns a list of LoyaltyProgram models.
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
            resource: LoyaltyProgramResource::class,
            filters: $request->get('with_filters')
                ? $this->service->getStructureFilters()
                : null
        );
    }

    /**
     * This method retrieves and returns a single LoyaltyProgram model based on the provided identifier.
     *
     * @param int $id
     * @return JsonResponse
     */
    public function show(int $id): JsonResponse
    {
        return ApiResponse::success(
            body: new LoyaltyProgramResource($this->service->show($id))
        );
    }

    /**
     * This method stores the provided data into storage for the LoyaltyProgram model.
     *
     * @param SaveLoyaltyProgramRequest $request
     * @return JsonResponse
     */
    public function store(SaveLoyaltyProgramRequest $request): JsonResponse
    {
        return ApiResponse::created(
            body: new LoyaltyProgramResource($this->service->store($request->validated())),
            resource: $this->service->label()
        );
    }

    /**
     * This method updates the provided data for the LoyaltyProgram model.
     *
     * @param SaveLoyaltyProgramRequest $request
     * @param int $id
     * @return JsonResponse
     */
    public function update(SaveLoyaltyProgramRequest $request, int $id): JsonResponse
    {
        return ApiResponse::updated(
            body: new LoyaltyProgramResource($this->service->update($id,$request->validated())),
            resource: $this->service->label()
        );
    }

    /**
     * This method deletes the LoyaltyProgram model based on the provided ids.
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
     * @return JsonResponse
     */
    public function getFormMeta(): JsonResponse
    {
        return ApiResponse::success($this->service->getFormMeta());
    }
}
