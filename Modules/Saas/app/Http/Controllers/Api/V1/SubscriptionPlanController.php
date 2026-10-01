<?php

namespace Modules\Saas\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\Controller;
use Modules\Saas\Http\Requests\Api\V1\SaveSubscriptionPlanRequest;
use Modules\Saas\Services\SubscriptionPlan\SubscriptionPlanServiceInterface;
use Modules\Saas\Transformers\Api\V1\SubscriptionPlanResource;
use Modules\Support\ApiResponse;

class SubscriptionPlanController extends Controller
{
    public function __construct(protected SubscriptionPlanServiceInterface $service)
    {
    }

    public function index(Request $request): JsonResponse
    {
        return ApiResponse::pagination(
            paginator: $this->service->get(
                filters: $request->get('filters', []),
                sorts: $request->get('sorts', []),
            ),
            resource: SubscriptionPlanResource::class,
            filters: $request->get('with_filters') ? $this->service->getStructureFilters() : null
        );
    }

    public function show(int $id): JsonResponse
    {
        return ApiResponse::success(new SubscriptionPlanResource($this->service->show($id)));
    }

    public function store(SaveSubscriptionPlanRequest $request): JsonResponse
    {
        return ApiResponse::created(
            body: new SubscriptionPlanResource($this->service->store($request->validated())),
            resource: $this->service->label()
        );
    }

    public function update(SaveSubscriptionPlanRequest $request, int $id): JsonResponse
    {
        return ApiResponse::updated(
            body: new SubscriptionPlanResource($this->service->update($id, $request->validated())),
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
