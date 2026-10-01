<?php

namespace Modules\User\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\Controller;
use Modules\Support\ApiResponse;
use Modules\User\Http\Requests\Api\V1\SaveEmployeeShiftRequest;
use Modules\User\Services\EmployeeShift\EmployeeShiftServiceInterface;
use Modules\User\Transformers\Api\V1\EmployeeShiftResource;

class EmployeeShiftController extends Controller
{
    public function __construct(protected EmployeeShiftServiceInterface $service)
    {
    }

    public function index(Request $request): JsonResponse
    {
        return ApiResponse::pagination(
            paginator: $this->service->get($request->get('filters', []), $request->get('sorts', [])),
            resource: EmployeeShiftResource::class,
            filters: $request->get('with_filters') ? $this->service->getStructureFilters() : null
        );
    }

    public function show(int $id): JsonResponse
    {
        return ApiResponse::success(new EmployeeShiftResource($this->service->show($id)));
    }

    public function store(SaveEmployeeShiftRequest $request): JsonResponse
    {
        return ApiResponse::created(
            body: new EmployeeShiftResource($this->service->store($request->validated())),
            resource: $this->service->label()
        );
    }

    public function update(SaveEmployeeShiftRequest $request, int $id): JsonResponse
    {
        return ApiResponse::updated(
            body: new EmployeeShiftResource($this->service->update($id, $request->validated())),
            resource: $this->service->label()
        );
    }

    public function destroy(string $ids): JsonResponse
    {
        return ApiResponse::destroyed(
            destroyed: $this->service->destroy($ids),
            resource: $this->service->label()
        );
    }

    public function getFormMeta(Request $request): JsonResponse
    {
        return ApiResponse::success($this->service->getFormMeta($request->integer('branch_id') ?: null));
    }
}
