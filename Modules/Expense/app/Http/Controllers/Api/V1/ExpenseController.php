<?php

namespace Modules\Expense\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\Controller;
use Modules\Expense\Http\Requests\Api\V1\SaveExpenseRequest;
use Modules\Expense\Services\Expense\ExpenseServiceInterface;
use Modules\Expense\Transformers\Api\V1\ExpenseResource;
use Modules\Support\ApiResponse;

class ExpenseController extends Controller
{
    public function __construct(protected ExpenseServiceInterface $service)
    {
    }

    public function index(Request $request): JsonResponse
    {
        return ApiResponse::pagination(
            paginator: $this->service->get(
                filters: $request->get('filters', []),
                sorts: $request->get('sorts', []),
            ),
            resource: ExpenseResource::class,
        );
    }

    public function show(int $id): JsonResponse
    {
        return ApiResponse::success(
            body: new ExpenseResource($this->service->show($id))
        );
    }

    public function store(SaveExpenseRequest $request): JsonResponse
    {
        return ApiResponse::created(
            body: new ExpenseResource($this->service->store($request->validated())),
            resource: $this->service->label()
        );
    }

    public function update(SaveExpenseRequest $request, int $id): JsonResponse
    {
        return ApiResponse::updated(
            body: new ExpenseResource($this->service->update($id, $request->validated())),
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
