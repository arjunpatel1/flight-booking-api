<?php

namespace Modules\Product\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\Controller;
use Modules\Menu\Services\Menu\MenuServiceInterface;
use Modules\Product\Http\Requests\Api\V1\SaveProductRequest;
use Modules\Product\Services\Product\ProductServiceInterface;
use Modules\Product\Services\ProductIngredient\ProductIngredientServiceInterface;
use Modules\Product\Services\ProductOption\ProductOptionServiceInterface;
use Modules\Product\Transformers\Api\V1\ProductResource;
use Modules\Product\Transformers\Api\V1\ProductShowResource;
use Modules\Support\ApiResponse;
use Throwable;

class ProductController extends Controller
{
    /**
     * Create a new instance of ProductController
     *
     * @param ProductServiceInterface $service
     * @param ProductOptionServiceInterface $productOptionService
     * @param ProductIngredientServiceInterface $productIngredientService
     * @param MenuServiceInterface $menuService
     */
    public function __construct(protected ProductServiceInterface           $service,
                                protected ProductOptionServiceInterface     $productOptionService,
                                protected ProductIngredientServiceInterface $productIngredientService,
                                protected MenuServiceInterface              $menuService)
    {
    }

    /**
     * This method retrieves and returns a list of Product models.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $menu = $this->menuService->getCurrentMenu($request->get('filters')['menu_id'] ?? null);

        return ApiResponse::pagination(
            paginator: $this->service->get(
                filters: [
                    ...$request->get('filters', []),
                    "menu_id" => $menu?->id
                ],
                sorts: $request->get('sorts', []),
            ),
            resource: ProductResource::class,
            filters: $request->get('with_filters')
                ? $this->service->getStructureFilters()
                : null,
            defaultFilters: [
                "menu_id" => $menu?->id
            ]
        );
    }

    /**
     * This method retrieves and returns a single Product model based on the provided identifier.
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $menuId = null;
        if ($request->get('menu_id')) {
            $menuId = $this->menuService->getCurrentMenu($request->get('menu_id'))?->id;
            abort_if(is_null($menuId), 404);
        }

        return ApiResponse::success(
            body: new ProductShowResource($this->service->show($id, $menuId))
        );
    }

    /**
     * This method stores the provided data into storage for the Product model.
     *
     * @param SaveProductRequest $request
     * @return JsonResponse
     * @throws Throwable
     */
    public function store(SaveProductRequest $request): JsonResponse
    {
        $data = $request->validated();

        $product = $this->service->store($data);

        $this->productOptionService->syncOptions($product, $data['options'] ?? []);

        $this->productIngredientService->syncForProduct($product->id, $product->branch->id, $data['ingredients'] ?? []);

        return ApiResponse::created(
            body: new ProductResource($product),
            resource: $this->service->label()
        );
    }

    /**
     * This method updates the provided data for the Product model.
     *
     * @param SaveProductRequest $request
     * @param int $id
     * @return JsonResponse
     * @throws Throwable
     */
    public function update(SaveProductRequest $request, string $id): JsonResponse
    {
        $data = $request->validated();

        $product = $this->service->update($id, $data);

        $this->productOptionService->syncOptions($product, $data['options'] ?? []);
        $this->productIngredientService->syncForProduct($product->id, $product->branch->id, $data['ingredients'] ?? []);

        return ApiResponse::updated(
            body: new ProductResource($product),
            resource: $this->service->label()
        );
    }
    
    /**
     * This method updates the provided data for the Product model.
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     * @throws Throwable
     */
    public function updateAvailability(Request $request, string $id): JsonResponse
    {
        $product = $this->service->update($id, $request->all());
        return ApiResponse::success();
    }

    public function merchandisingRecommendations(Request $request): JsonResponse
    {
        $menu = $this->menuService->getCurrentMenu($request->get('menu_id'));

        return ApiResponse::success(
            body: $this->service->merchandisingRecommendations($menu)
        );
    }

    public function applyMerchandisingRecommendations(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'menu_id' => ['nullable', 'integer', 'exists:menus,id'],
            'product_ids' => ['nullable', 'array'],
            'product_ids.*' => ['integer', 'exists:products,id'],
        ]);
        $menu = $this->menuService->getCurrentMenu($validated['menu_id'] ?? null);

        return ApiResponse::success(
            body: $this->service->applyMerchandisingRecommendations($validated, $menu)
        );
    }

    /**
     * This method deletes the Product model based on the provided ids.
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
     * @param Request $request
     * @return JsonResponse
     */
    public function getFormMeta(Request $request): JsonResponse
    {
        $menu = $this->menuService->getCurrentMenu($request->get('menu_id'), true);

        return ApiResponse::success($this->service->getFormMeta($menu));
    }
}
