<?php

namespace Modules\Report\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\Controller;
use Modules\Report\Http\Requests\Api\V1\SaveWaiterSettlementRequest;
use Modules\Report\Services\WaiterSettlement\WaiterSettlementServiceInterface;
use Modules\Report\Transformers\Api\V1\WaiterSettlementResource;
use Modules\Support\ApiResponse;
use Modules\Support\Money;

class WaiterSettlementController extends Controller
{
    public function __construct(protected WaiterSettlementServiceInterface $service)
    {
    }

    public function index(Request $request): JsonResponse
    {
        return ApiResponse::pagination(
            paginator: $this->service->get(
                filters: $request->get('filters', []),
                sorts: $request->get('sorts', []),
            ),
            resource: WaiterSettlementResource::class,
        );
    }

    public function preview(Request $request): JsonResponse
    {
        $request->validate([
            'waiter_id' => 'required|integer|exists:users,id',
            'business_date' => 'required|date|date_format:Y-m-d',
        ]);

        $preview = $this->service->preview(
            $request->integer('waiter_id'),
            $request->string('business_date')->toString(),
        );

        return ApiResponse::success(body: [
            'waiter_id' => $preview['waiter_id'],
            'business_date' => $preview['business_date'],
            'expected_amount' =>
                (new Money($preview['expected_amount'], $preview['currency']))->toArray(),
            'settlement' => $preview['settlement']
                ? new WaiterSettlementResource($preview['settlement'])
                : null,
        ]);
    }

    public function store(SaveWaiterSettlementRequest $request): JsonResponse
    {
        return ApiResponse::created(
            body: new WaiterSettlementResource(
                $this->service->store($request->validated())
            ),
            resource: $this->service->label()
        );
    }
}
