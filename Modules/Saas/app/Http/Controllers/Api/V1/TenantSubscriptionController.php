<?php

namespace Modules\Saas\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\Controller;
use Modules\Saas\Http\Requests\Api\V1\SaveTenantSubscriptionRequest;
use Modules\Saas\Services\TenantSubscription\TenantSubscriptionServiceInterface;
use Modules\Saas\Transformers\Api\V1\TenantSubscriptionResource;
use Modules\Support\ApiResponse;

class TenantSubscriptionController extends Controller
{
    public function __construct(protected TenantSubscriptionServiceInterface $service)
    {
    }

    public function index(Request $request): JsonResponse
    {
        return ApiResponse::pagination(
            paginator: $this->service->get(
                filters: $request->get('filters', []),
                sorts: $request->get('sorts', []),
            ),
            resource: TenantSubscriptionResource::class,
            filters: $request->get('with_filters') ? $this->service->getStructureFilters() : null
        );
    }

    public function show(int $id): JsonResponse
    {
        return ApiResponse::success(new TenantSubscriptionResource($this->service->show($id)));
    }

    public function store(SaveTenantSubscriptionRequest $request): JsonResponse
    {
        return ApiResponse::created(
            body: new TenantSubscriptionResource($this->service->store($request->validated())),
            resource: $this->service->label()
        );
    }

    public function update(SaveTenantSubscriptionRequest $request, int $id): JsonResponse
    {
        return ApiResponse::updated(
            body: new TenantSubscriptionResource($this->service->update($id, $request->validated())),
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

    public function getFormMeta(): JsonResponse
    {
        return ApiResponse::success($this->service->getFormMeta());
    }
}
