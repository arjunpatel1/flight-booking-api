<?php

namespace Modules\Aggregator\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Aggregator\Http\Requests\Api\V1\AggregatorItemAvailabilityRequest;
use Modules\Aggregator\Http\Requests\Api\V1\SaveAggregatorIntegrationRequest;
use Modules\Aggregator\Services\Integration\AggregatorIntegrationServiceInterface;
use Modules\Aggregator\Services\ItemAvailability\AggregatorItemAvailabilityServiceInterface;
use Modules\Aggregator\Transformers\Api\V1\AggregatorIntegrationResource;
use Modules\Core\Http\Controllers\Controller;
use Modules\Support\ApiResponse;

class AggregatorIntegrationController extends Controller
{
    public function __construct(
        protected AggregatorIntegrationServiceInterface $service,
        protected AggregatorItemAvailabilityServiceInterface $itemAvailabilityService,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        return ApiResponse::pagination(
            paginator: $this->service->get(
                filters: $request->get('filters', []),
                sorts: $request->get('sorts', []),
            ),
            resource: AggregatorIntegrationResource::class,
            filters: $request->get('with_filters') && method_exists($this->service, 'getStructureFilters')
                ? $this->service->getStructureFilters()
                : null
        );
    }

    public function show(int $id): JsonResponse
    {
        return ApiResponse::success(
            body: new AggregatorIntegrationResource($this->service->show($id))
        );
    }

    public function store(SaveAggregatorIntegrationRequest $request): JsonResponse
    {
        return ApiResponse::created(
            body: new AggregatorIntegrationResource($this->service->store($request->validated())),
            resource: $this->service->label()
        );
    }

    public function update(SaveAggregatorIntegrationRequest $request, int $id): JsonResponse
    {
        return ApiResponse::updated(
            body: new AggregatorIntegrationResource($this->service->update($id, $request->validated())),
            resource: $this->service->label()
        );
    }

    public function toggle(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        return ApiResponse::updated(
            body: new AggregatorIntegrationResource($this->service->toggle($id, $data['is_active'])),
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

    public function sync(Request $request, int $id): JsonResponse
    {
        $type = $request->get('type', 'menu');
        $this->service->dispatchSync($id, $type);

        return ApiResponse::success(message: __('admin::messages.resource_saved', ['resource' => $this->service->label()]));
    }

    /**
     * Push item availability ("86") changes to the aggregator so out-of-stock items
     * are hidden on its storefront.
     */
    public function itemAvailability(AggregatorItemAvailabilityRequest $request, int $id): JsonResponse
    {
        $log = $this->itemAvailabilityService->sync($id, [
            ...$request->validated(),
            'manual' => true,
        ]);

        return ApiResponse::success(
            body: ['sync_log_id' => $log->id, 'status' => $log->status],
            message: __('admin::messages.resource_saved', ['resource' => $this->service->label()])
        );
    }

    public function providerStatus(int $id): JsonResponse
    {
        return ApiResponse::success($this->service->providerStatus($id));
    }

    public function testConnection(int $id): JsonResponse
    {
        return ApiResponse::success($this->service->testConnection($id));
    }
}
