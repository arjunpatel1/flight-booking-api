<?php

namespace Modules\Product\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\Controller;
use Modules\Product\Http\Requests\Api\V1\SaveProductFavoriteRequest;
use Modules\Product\Services\ProductFavorite\ProductFavoriteServiceInterface;
use Modules\Product\Transformers\Api\V1\ProductFavoriteResource;
use Modules\Support\ApiResponse;

class ProductFavoriteController extends Controller
{
    /**
     * Create a new instance of ProductFavoriteController
     *
     * @param ProductFavoriteServiceInterface $service
     */
    public function __construct(protected ProductFavoriteServiceInterface $service)
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
        $filters = $request->get('filters', []);
        // Favorites are strictly per-user: force the owner to the authenticated
        // user so a client cannot read another user's favorites by passing a
        // different filters[user_id].
        $filters['user_id'] = $request->user()?->id;
        $filters['branch_id'] ??= $request->user()?->branch_id;
        $filters['per_page'] ??= $request->integer('per_page', 100);

        return ApiResponse::pagination(
            paginator: $this->service->get(
                filters: $filters,
                sorts: $request->get('sorts', []),
            ),
            resource: ProductFavoriteResource::class,
        );
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param SaveProductFavoriteRequest $request
     * @return JsonResponse
     */
    public function store(SaveProductFavoriteRequest $request): JsonResponse
    {
        $data = $request->validated();

        $favorite = $this->service->store($data);

        return ApiResponse::created(
            body: new ProductFavoriteResource($favorite),
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
     * Toggle favorite status for a user and product.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function toggle(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => ['nullable', 'integer', 'exists:users,id,deleted_at,NULL'],
            'product_id' => ['required', 'integer', 'exists:products,id,deleted_at,NULL'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id,deleted_at,NULL'],
        ]);
        $userId = $validated['user_id'] ?? $request->user()?->id;
        $branchId = $validated['branch_id'] ?? $request->user()?->branch_id;

        $result = $this->service->toggle(
            $userId,
            $validated['product_id'],
            $branchId
        );

        return ApiResponse::success(
            body: [
                'is_favorited' => $result['is_favorited'],
                'product_id' => $validated['product_id'],
                'favorite' => $result['favorite']
                    ? new ProductFavoriteResource($result['favorite'])
                    : null,
            ]
        );
    }

    /**
     * Check if a product is favorited by a user.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function check(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => ['nullable', 'integer', 'exists:users,id,deleted_at,NULL'],
            'product_id' => ['required', 'integer', 'exists:products,id,deleted_at,NULL'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id,deleted_at,NULL'],
        ]);
        $userId = $validated['user_id'] ?? $request->user()?->id;
        $branchId = $validated['branch_id'] ?? $request->user()?->branch_id;

        $isFavorited = $this->service->isFavorited(
            $userId,
            $validated['product_id'],
            $branchId
        );

        return ApiResponse::success(
            body: [
                'is_favorited' => $isFavorited,
            ]
        );
    }
}
