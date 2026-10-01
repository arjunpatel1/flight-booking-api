<?php

namespace Modules\Hotels\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\Controller;
use Modules\Hotels\Http\Requests\Api\V1\SaveHotelRequest;
use Modules\Hotels\Services\Hotel\HotelServiceInterface;
use Modules\Hotels\Transformers\Api\V1\HotelResource;
use Modules\Support\ApiResponse;

class HotelController extends Controller
{
    /**
     * Create a new instance of HotelController
     *
     * @param HotelServiceInterface $service
     */
    public function __construct(protected HotelServiceInterface $service)
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
            paginator: $this->service->get(
                filters: $request->get('filters', []),
                sorts: $request->get('sorts', []),
            ),
            resource: HotelResource::class,
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
     * Display the specified resource.
     *
     * @param int $id
     * @return JsonResponse
     */
    public function show(int $id): JsonResponse
    {
        return ApiResponse::success(
            body: new HotelResource($this->service->show($id))
        );
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param SaveHotelRequest $request
     * @return JsonResponse
     */
    public function store(SaveHotelRequest $request): JsonResponse
    {
        return ApiResponse::created(
            body: new HotelResource($this->service->store($request->validated())),
            resource: $this->service->label()
        );
    }

    /**
     * Update the specified resource in storage.
     *
     * @param SaveHotelRequest $request
     * @param int $id
     * @return JsonResponse
     */
    public function update(SaveHotelRequest $request, int $id): JsonResponse
    {
        return ApiResponse::updated(
            body: new HotelResource($this->service->update($id, $request->validated())),
            resource: $this->service->label()
        );
    }

    /**
     * Remove the specified resource from storage.
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
     * Delete a single hotel by ID
     *
     * @param int $id
     * @return JsonResponse
     */
    public function delete(int $id): JsonResponse
    {
        return ApiResponse::destroyed(
            destroyed: $this->service->destroy($id),
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
        return ApiResponse::success(
            $this->service->getFormMeta(
                auth()->user()->assignedToBranch()
                    ? auth()->user()->branch_id
                    : $request->get('branch_id')
            )
        );
    }
}
