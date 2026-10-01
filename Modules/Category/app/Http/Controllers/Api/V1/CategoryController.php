<?php

namespace Modules\Category\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Category\Http\Requests\Api\V1\SaveCategoryRequest;
use Modules\Category\Http\Responses\CategoryTreeResponse;
use Modules\Category\Services\Category\CategoryServiceInterface;
use Modules\Category\Services\CategoryTreeUpdater\CategoryTreeUpdaterServiceInterface;
use Modules\Category\Transformers\Api\V1\CategoryResource;
use Modules\Core\Http\Controllers\Controller;
use Modules\Menu\Services\Menu\MenuServiceInterface;
use Modules\Support\ApiResponse;
use Throwable;

class CategoryController extends Controller
{
    /**
     * Create a new instance of CategoryController
     *
     * @param CategoryServiceInterface $service
     * @param CategoryTreeUpdaterServiceInterface $updaterService
     * @param MenuServiceInterface $menuService
     */
    public function __construct(
        protected CategoryServiceInterface            $service,
        protected CategoryTreeUpdaterServiceInterface $updaterService,
        protected MenuServiceInterface                $menuService)
    {
    }

    /**
     * This method retrieves and returns a list of Category models.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $menu = $this->menuService->getCurrentMenu($request->get('filters')['menu_id'] ?? null);
        $categories = null;

        if (!is_null($menu)) {
            $categories = new CategoryTreeResponse($this->service->getForTree(filters: ['menu_id' => $menu->id]));
        }

        return ApiResponse::success([
            "categories" => $categories?->transform(),
            "menu" => !is_null($menu)
                ? [
                    "id" => $menu->id,
                    "name" => $menu->name,
                ]
                : null
        ]);
    }

    /**
     * This method retrieves and returns a single Category model based on the provided identifier.
     *
     * @param int $id
     * @return JsonResponse
     */
    public function show(int $id): JsonResponse
    {
        return ApiResponse::success(
            body: new CategoryResource($this->service->show($id))
        );
    }

    /**
     * This method stores the provided data into storage for the Category model.
     *
     * @param SaveCategoryRequest $request
     * @return JsonResponse
     */
    public function store(SaveCategoryRequest $request): JsonResponse
    {
        return ApiResponse::created(
            body: new CategoryResource($this->service->store($request->validated())),
            resource: $this->service->label()
        );
    }

    /**
     * Update category tree order.
     *
     * @param Request $request
     * @param int $menuId
     * @return JsonResponse
     * @throws Throwable
     */
    public function updateTree(Request $request, int $menuId): JsonResponse
    {
        $this->menuService->getCurrentMenu($menuId);
        $this->updaterService->update($menuId, $request->get('tree', []));

        return ApiResponse::success(message: __("category::messages.category_order_updated"));
    }

    /**
     * This method updates the provided data for the Category model.
     *
     * @param SaveCategoryRequest $request
     * @param int $id
     * @return JsonResponse
     */
    public function update(SaveCategoryRequest $request, int $id): JsonResponse
    {
        return ApiResponse::updated(
            body: new CategoryResource($this->service->update($id, $request->validated())),
            resource: $this->service->label()
        );
    }

    /**
     * This method deletes the Category model based on the provided ids.
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
}
