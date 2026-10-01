<?php

namespace Modules\Aggregator\Http\Controllers\Api\V1;

use App\NexDine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Aggregator\Models\AggregatorSyncLog;
use Modules\Aggregator\Services\Integration\AggregatorIntegrationServiceInterface;
use Modules\Aggregator\Transformers\Api\V1\AggregatorSyncLogResource;
use Modules\Core\Http\Controllers\Controller;
use Modules\Support\ApiResponse;

class AggregatorSyncLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return ApiResponse::pagination(
            paginator: AggregatorSyncLog::query()
                ->with(['integration:id,name'])
                ->filters($request->get('filters', []))
                ->sortBy($request->get('sorts', []))
                ->latest()
                ->paginate(NexDine::paginate())
                ->withQueryString(),
            resource: AggregatorSyncLogResource::class
        );
    }

    public function show(int $id): JsonResponse
    {
        return ApiResponse::success(new AggregatorSyncLogResource(
            AggregatorSyncLog::query()->with(['integration:id,name'])->findOrFail($id)
        ));
    }

    public function retry(AggregatorIntegrationServiceInterface $service, int $id): JsonResponse
    {
        $service->retryLog($id);

        return ApiResponse::success(message: __('admin::messages.resource_saved', ['resource' => __('aggregator::aggregator.sync_log')]));
    }
}
