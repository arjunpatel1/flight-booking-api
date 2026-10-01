<?php

namespace Modules\Printer\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\Controller;
use Modules\Printer\Services\PrintJob\PrintJobServiceInterface;
use Modules\Printer\Transformers\Api\V1\PrintJobResource;
use Modules\Support\ApiResponse;

class PrintJobController extends Controller
{
    public function __construct(protected PrintJobServiceInterface $service)
    {
    }

    /**
     * Retrieve print jobs.
     */
    public function index(Request $request): JsonResponse
    {
        return ApiResponse::pagination(
            paginator: $this->service->get(
                filters: $request->get('filters', []),
                sorts: $request->get('sorts', []),
            ),
            resource: PrintJobResource::class,
            filters: $request->get('with_filters')
                ? $this->service->getStructureFilters()
                : null
        );
    }

    /**
     * Retry a pending or failed print job.
     */
    public function retry(string $id): JsonResponse
    {
        return ApiResponse::updated(
            body: new PrintJobResource($this->service->retry($id)),
            resource: $this->service->label()
        );
    }

    /**
     * Retrieve print queue summary.
     */
    public function summary(Request $request): JsonResponse
    {
        return ApiResponse::success(
            body: $this->service->summary($request->get('filters', []))
        );
    }

    /**
     * Retrieve printer and local agent diagnostics.
     */
    public function diagnostics(Request $request): JsonResponse
    {
        return ApiResponse::success(
            body: $this->service->diagnostics($request->get('filters', []))
        );
    }
}
