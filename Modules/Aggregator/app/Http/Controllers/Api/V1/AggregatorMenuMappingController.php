<?php

namespace Modules\Aggregator\Http\Controllers\Api\V1;

use App\NexDine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Aggregator\Http\Requests\Api\V1\SaveAggregatorMenuMappingRequest;
use Modules\Aggregator\Models\AggregatorIntegration;
use Modules\Aggregator\Models\AggregatorMenuMapping;
use Modules\Aggregator\Transformers\Api\V1\AggregatorMenuMappingResource;
use Modules\Core\Http\Controllers\Controller;
use Modules\Menu\Models\Menu;
use Modules\Support\ApiResponse;

class AggregatorMenuMappingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return ApiResponse::pagination(
            paginator: AggregatorMenuMapping::query()
                ->with(['integration:id,name', 'menu:id,name'])
                ->filters($request->get('filters', []))
                ->sortBy($request->get('sorts', []))
                ->paginate(NexDine::paginate())
                ->withQueryString(),
            resource: AggregatorMenuMappingResource::class
        );
    }

    public function show(int $id): JsonResponse
    {
        return ApiResponse::success(new AggregatorMenuMappingResource(
            AggregatorMenuMapping::query()->with(['integration:id,name', 'menu:id,name'])->findOrFail($id)
        ));
    }

    public function store(SaveAggregatorMenuMappingRequest $request): JsonResponse
    {
        return ApiResponse::created(
            new AggregatorMenuMappingResource(AggregatorMenuMapping::query()->create($request->validated())),
            __('aggregator::aggregator.menu_mapping')
        );
    }

    public function update(SaveAggregatorMenuMappingRequest $request, int $id): JsonResponse
    {
        $mapping = AggregatorMenuMapping::query()->findOrFail($id);
        $mapping->update($request->validated());

        return ApiResponse::updated(new AggregatorMenuMappingResource($mapping), __('aggregator::aggregator.menu_mapping'));
    }

    public function destroy(string $ids): JsonResponse
    {
        return ApiResponse::destroyed(
            AggregatorMenuMapping::query()->whereIn('id', parseIds($ids))->delete() ?: false,
            __('aggregator::aggregator.menu_mapping')
        );
    }

    public function getFormMeta(): JsonResponse
    {
        return ApiResponse::success([
            'integrations' => AggregatorIntegration::query()->select('id', 'name')->where('is_active', true)->get(),
            'menus' => Menu::list(withoutGlobalActive: true),
        ]);
    }
}
