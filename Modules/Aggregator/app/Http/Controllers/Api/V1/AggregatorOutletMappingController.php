<?php

namespace Modules\Aggregator\Http\Controllers\Api\V1;

use App\NexDine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Aggregator\Http\Requests\Api\V1\SaveAggregatorOutletMappingRequest;
use Modules\Aggregator\Models\AggregatorIntegration;
use Modules\Aggregator\Models\AggregatorOutletMapping;
use Modules\Aggregator\Transformers\Api\V1\AggregatorOutletMappingResource;
use Modules\Branch\Models\Branch;
use Modules\Core\Http\Controllers\Controller;
use Modules\Support\ApiResponse;

class AggregatorOutletMappingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return ApiResponse::pagination(
            paginator: AggregatorOutletMapping::query()
                ->with(['integration:id,name', 'branch:id,name'])
                ->filters($request->get('filters', []))
                ->sortBy($request->get('sorts', []))
                ->paginate(NexDine::paginate())
                ->withQueryString(),
            resource: AggregatorOutletMappingResource::class
        );
    }

    public function show(int $id): JsonResponse
    {
        return ApiResponse::success(new AggregatorOutletMappingResource(
            AggregatorOutletMapping::query()->with(['integration:id,name', 'branch:id,name'])->findOrFail($id)
        ));
    }

    public function store(SaveAggregatorOutletMappingRequest $request): JsonResponse
    {
        return ApiResponse::created(
            new AggregatorOutletMappingResource(AggregatorOutletMapping::query()->create($request->validated())),
            __('aggregator::aggregator.outlet_mapping')
        );
    }

    public function update(SaveAggregatorOutletMappingRequest $request, int $id): JsonResponse
    {
        $mapping = AggregatorOutletMapping::query()->findOrFail($id);
        $mapping->update($request->validated());

        return ApiResponse::updated(new AggregatorOutletMappingResource($mapping), __('aggregator::aggregator.outlet_mapping'));
    }

    public function destroy(string $ids): JsonResponse
    {
        return ApiResponse::destroyed(
            AggregatorOutletMapping::query()->whereIn('id', parseIds($ids))->delete() ?: false,
            __('aggregator::aggregator.outlet_mapping')
        );
    }

    public function getFormMeta(): JsonResponse
    {
        return ApiResponse::success([
            'integrations' => AggregatorIntegration::query()->select('id', 'name')->where('is_active', true)->get(),
            'branches' => Branch::list(),
        ]);
    }
}
