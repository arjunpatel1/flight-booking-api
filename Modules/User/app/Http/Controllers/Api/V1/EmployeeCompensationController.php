<?php

namespace Modules\User\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\Controller;
use Modules\Support\ApiResponse;
use Modules\User\Http\Requests\Api\V1\SaveEmployeeCompensationRequest;
use Modules\User\Services\EmployeeCompensation\EmployeeCompensationServiceInterface;
use Modules\User\Transformers\Api\V1\EmployeeCompensationResource;

class EmployeeCompensationController extends Controller
{
    public function __construct(protected EmployeeCompensationServiceInterface $service)
    {
    }

    public function index(Request $request): JsonResponse
    {
        return ApiResponse::pagination(
            paginator: $this->service->get($request->get('filters', []), $request->get('sorts', [])),
            resource: EmployeeCompensationResource::class,
            filters: $request->get('with_filters') ? $this->service->getStructureFilters() : null
        );
    }

    public function getFormMeta(Request $request): JsonResponse
    {
        return ApiResponse::success($this->service->getFormMeta($request->integer('branch_id') ?: null));
    }

    public function show(int $id): JsonResponse
    {
        return ApiResponse::success(new EmployeeCompensationResource($this->service->show($id)));
    }

    public function store(SaveEmployeeCompensationRequest $request): JsonResponse
    {
        return ApiResponse::created(
            body: new EmployeeCompensationResource($this->service->store($request->validated())),
            resource: $this->service->label()
        );
    }

    public function update(SaveEmployeeCompensationRequest $request, int $id): JsonResponse
    {
        return ApiResponse::updated(
            body: new EmployeeCompensationResource($this->service->update($id, $request->validated())),
            resource: $this->service->label()
        );
    }

    public function destroy(string $ids): JsonResponse
    {
        return ApiResponse::destroyed($this->service->destroy($ids), $this->service->label());
    }
}
