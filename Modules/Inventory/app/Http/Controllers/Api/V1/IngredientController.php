<?php

namespace Modules\Inventory\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Core\Http\Controllers\Controller;
use Modules\Inventory\Enums\StockMovementType;
use Modules\Inventory\Http\Requests\Api\V1\SaveIngredientRequest;
use Modules\Inventory\Models\Ingredient;
use Modules\Inventory\Models\StockMovement;
use Modules\Inventory\Services\Ingredient\IngredientServiceInterface;
use Modules\Inventory\Transformers\Api\V1\IngredientResource;
use Modules\Support\ApiResponse;

class IngredientController extends Controller
{
    /**
     * Create a new instance of IngredientController
     */
    public function __construct(protected IngredientServiceInterface $service) {}

    /**
     * This method retrieves and returns a list of Ingredient models.
     */
    public function index(Request $request): JsonResponse
    {
        return ApiResponse::pagination(
            paginator: $this->service->get(
                filters: $request->get('filters', []),
                sorts: $request->get('sorts', []),
            ),
            resource: IngredientResource::class,
            filters: $request->get('with_filters')
                ? $this->service->getStructureFilters()
                : null
        );
    }

    /**
     * This method retrieves and returns a single Ingredient model based on the provided identifier.
     */
    public function show(int $id): JsonResponse
    {
        return ApiResponse::success(
            body: new IngredientResource($this->service->show($id))
        );
    }

    /**
     * This method stores the provided data into storage for the Ingredient model.
     */
    public function store(SaveIngredientRequest $request): JsonResponse
    {
        return ApiResponse::created(
            body: new IngredientResource($this->service->store($request->validated())),
            resource: $this->service->label()
        );
    }

    /**
     * This method updates the provided data for the Ingredient model.
     */
    public function update(SaveIngredientRequest $request, int $id): JsonResponse
    {
        return ApiResponse::updated(
            body: new IngredientResource($this->service->update($id, $request->validated())),
            resource: $this->service->label()
        );
    }

    /**
     * This method deletes the Ingredient model based on the provided ids.
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
     */
    public function getFormMeta(): JsonResponse
    {
        return ApiResponse::success($this->service->getFormMeta());
    }

    /**
     * Quick stock adjustment for an ingredient.
     */
    public function quickAdjust(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'current_stock' => ['required', 'numeric', 'min:0'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        [$ingredient, $oldStock, $difference, $movement] = DB::transaction(function () use ($id, $data) {
            $ingredient = Ingredient::query()->lockForUpdate()->findOrFail($id);
            $oldStock = (float) $ingredient->current_stock;
            $newStock = (float) $data['current_stock'];
            $difference = round($newStock - $oldStock, 4);

            if ($difference == 0.0) {
                return [$ingredient, $oldStock, $difference, null];
            }

            $movement = StockMovement::query()->create([
                'branch_id' => $ingredient->branch_id,
                'ingredient_id' => $ingredient->id,
                'type' => $difference > 0
                    ? StockMovementType::AdjustAdd
                    : StockMovementType::AdjustSubtract,
                'quantity' => abs($difference),
                'note' => $data['reason'] ?? null,
            ]);

            return [$ingredient, $oldStock, $difference, $movement];
        });

        $ingredient->refresh();

        return ApiResponse::success([
            'id' => $ingredient->id,
            'name' => $ingredient->name,
            'old_stock' => $oldStock,
            'new_stock' => $ingredient->current_stock,
            'difference' => $difference,
            'movement_id' => $movement?->id,
            'reason' => $data['reason'] ?? null,
            'adjusted_at' => now()->toIso8601String(),
        ]);
    }
}
