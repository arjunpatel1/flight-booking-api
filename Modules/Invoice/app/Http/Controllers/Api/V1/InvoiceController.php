<?php

namespace Modules\Invoice\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\Controller;
use Modules\Invoice\Services\Invoice\InvoiceServiceInterface;
use Modules\Invoice\Transformers\Api\V1\InvoiceResource;
use Modules\Invoice\Transformers\Api\V1\ShowInvoiceResource;
use Modules\Support\ApiResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InvoiceController extends Controller
{
    /**
     * Create a new instance of InvoiceController
     *
     * @param InvoiceServiceInterface $service
     */
    public function __construct(protected InvoiceServiceInterface $service)
    {
    }

    /**
     * This method retrieves and returns a list of Invoice models.
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
            resource: InvoiceResource::class,
            filters: $request->get('with_filters')
                ? $this->service->getStructureFilters()
                : null
        );
    }

    /**
     * This method retrieves and returns a single Invoice model based on the provided identifier.
     *
     * @param int|string $id
     * @return JsonResponse
     */
    public function show(int|string $id): JsonResponse
    {
        return ApiResponse::success(
            body: new ShowInvoiceResource($this->service->show($id))
        );
    }

    public function summary(Request $request): JsonResponse
    {
        return ApiResponse::success($this->service->summary($request->get('filters', [])));
    }

    public function export(Request $request): StreamedResponse
    {
        return $this->service->export(
            filters: $request->get('filters', []),
            sorts: $request->get('sorts', [])
        );
    }
}
