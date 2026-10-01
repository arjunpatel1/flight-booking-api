<?php

namespace Modules\Menu\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Modules\Core\Http\Controllers\Controller;
use Modules\Menu\Http\Requests\Api\V1\SaveMenuRequest;
use Modules\Menu\Services\Menu\MenuServiceInterface;
use Modules\Menu\Transformers\Api\V1\MenuResource;
use Modules\Support\ApiResponse;

class MenuController extends Controller
{
    /**
     * Create a new instance of MenuController
     *
     * @param MenuServiceInterface $service
     */
    public function __construct(protected MenuServiceInterface $service)
    {
    }

    /**
     * This method retrieves and returns a list of Menu models.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        return ApiResponse::pagination(
            paginator: $this->service->get(
                filters: $request->get('filters', []),
                sorts: $request->get('sorts', []),
            ),
            resource: MenuResource::class,
            filters: $request->get('with_filters')
                ? $this->service->getStructureFilters()
                : null
        );
    }
    
    /**
     * This method retrieves and returns a single Menu model based on the provided identifier.
     *
     * @param int $id
     * @return JsonResponse
     */
    public function show(int $id): JsonResponse
    {
        return ApiResponse::success(
            body: new MenuResource($this->service->show($id))
        );
    }

    /**
     * This method stores the provided data into storage for the Menu model.
     *
     * @param SaveMenuRequest $request
     * @return JsonResponse
     */
    public function store(SaveMenuRequest $request): JsonResponse
    {
        return ApiResponse::created(
            body: new MenuResource($this->service->store($request->validated())),
            resource: $this->service->label()
        );
    }

    /**
     * This method updates the provided data for the Menu model.
     *
     * @param SaveMenuRequest $request
     * @param int $id
     * @return JsonResponse
     */
    public function update(SaveMenuRequest $request, int $id): JsonResponse
    {
        return ApiResponse::updated(
            body: new MenuResource($this->service->update($id, $request->validated())),
            resource: $this->service->label()
        );
    }

    public function copy(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'branch_id' => ['required', 'integer'],
            'name' => ['nullable', 'string', 'max:255'],
            'activate' => ['nullable', 'boolean'],
        ]);

        return ApiResponse::created(
            body: new MenuResource($this->service->copyToBranch(
                $id,
                (int) $data['branch_id'],
                $data['name'] ?? null,
                (bool) ($data['activate'] ?? false),
            )),
            resource: $this->service->label(),
        );
    }

    public function export(Request $request): StreamedResponse
    {
        $ids = collect(explode(',', (string) $request->query('ids', '')))
            ->filter()->map(fn ($id) => (int) $id)->filter()->unique()->values();
        $menus = $this->service->getModel()->query()
            ->withoutGlobalActive()
            ->when($ids->isNotEmpty(), fn ($query) => $query->whereKey($ids->all()))
            ->orderBy('id')
            ->get();
        $headers = ['name_en', 'name_ar', 'description_en', 'description_ar', 'branch_id', 'order_types', 'is_active'];

        return response()->streamDownload(function () use ($headers, $menus): void {
            $stream = fopen('php://output', 'w');
            fputcsv($stream, $headers);
            foreach ($menus as $menu) {
                $names = $menu->getTranslations('name');
                $descriptions = $menu->getTranslations('description');
                fputcsv($stream, [
                    $names['en'] ?? '', $names['ar'] ?? '',
                    $descriptions['en'] ?? '', $descriptions['ar'] ?? '',
                    $menu->branch_id, implode('|', $menu->order_types ?: []), $menu->is_active ? '1' : '0',
                ]);
            }
            fclose($stream);
        }, 'menus-import-ready-' . now()->format('Y-m-d') . '.csv', ['Content-Type' => 'text/csv']);
    }

    /**
     * This method deletes the Menu model based on the provided ids.
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
     * @return JsonResponse
     */
    public function getFormMeta(): JsonResponse
    {
        return ApiResponse::success($this->service->getFormMeta());
    }
}
