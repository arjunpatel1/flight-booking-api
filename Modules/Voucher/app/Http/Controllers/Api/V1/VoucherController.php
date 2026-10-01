<?php

namespace Modules\Voucher\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\Controller;
use Modules\Support\ApiResponse;
use Modules\Voucher\Http\Requests\Api\V1\SaveVoucherRequest;
use Modules\Voucher\Services\Voucher\VoucherServiceInterface;
use Modules\Voucher\Transformers\Api\V1\ShowVoucherResource;
use Modules\Voucher\Transformers\Api\V1\VoucherResource;

class VoucherController extends Controller
{
    /**
     * Create a new instance of VoucherController
     *
     * @param VoucherServiceInterface $service
     */
    public function __construct(protected VoucherServiceInterface $service)
    {
    }

    /**
     * This method retrieves and returns a list of Voucher models.
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
            resource: VoucherResource::class,
            filters: $request->get('with_filters')
                ? $this->service->getStructureFilters()
                : null
        );
    }

    /**
     * This method retrieves and returns a single Voucher model based on the provided identifier.
     *
     * @param int $id
     * @return JsonResponse
     */
    public function show(int $id): JsonResponse
    {
        return ApiResponse::success(
            body: new ShowVoucherResource($this->service->show($id))
        );
    }

    /**
     * This method stores the provided data into storage for the Voucher model.
     *
     * @param SaveVoucherRequest $request
     * @return JsonResponse
     */
    public function store(SaveVoucherRequest $request): JsonResponse
    {
        return ApiResponse::created(
            body: new VoucherResource($this->service->store($request->validated())),
            resource: $this->service->label()
        );
    }

    /**
     * This method updates the provided data for the Voucher model.
     *
     * @param SaveVoucherRequest $request
     * @param int $id
     * @return JsonResponse
     */
    public function update(SaveVoucherRequest $request, int $id): JsonResponse
    {
        return ApiResponse::updated(
            body: new VoucherResource($this->service->update($id, $request->validated())),
            resource: $this->service->label()
        );
    }

    /**
     * This method deletes the Voucher model based on the provided ids.
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
