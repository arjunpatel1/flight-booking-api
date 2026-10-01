<?php

namespace Modules\Printer\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\Controller;
use Modules\Printer\Http\Requests\Api\V1\SavePrinterRequest;
use Modules\Printer\Services\Printer\PrinterServiceInterface;
use Modules\Printer\Transformers\Api\V1\PrinterResource;
use Modules\Printer\Transformers\Api\V1\PrinterShowResource;
use Modules\Support\ApiResponse;

class PrinterController extends Controller
{
    /**
     * Create a new instance of PrinterController
     *
     * @param PrinterServiceInterface $service
     */
    public function __construct(protected PrinterServiceInterface $service)
    {
    }

    /**
     * This method retrieves and returns a list of Printer models.
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
            resource: PrinterResource::class,
            filters: $request->get('with_filters')
                ? $this->service->getStructureFilters()
                : null
        );
    }

    /**
     * This method retrieves and returns a single Printer model based on the provided identifier.
     *
     * @param int $id
     * @return JsonResponse
     */
    public function show(int $id): JsonResponse
    {
        return ApiResponse::success(
            body: new PrinterShowResource($this->service->show($id))
        );
    }

    /**
     * This method stores the provided data into storage for the Printer model.
     *
     * @param SavePrinterRequest $request
     * @return JsonResponse
     */
    public function store(SavePrinterRequest $request): JsonResponse
    {
        return ApiResponse::created(
            body: new PrinterResource($this->service->store($request->validated())),
            resource: $this->service->label()
        );
    }

    /**
     * This method updates the provided data for the Printer model.
     *
     * @param SavePrinterRequest $request
     * @param int $id
     * @return JsonResponse
     */
    public function update(SavePrinterRequest $request, int $id): JsonResponse
    {
        return ApiResponse::updated(
            body: new PrinterResource($this->service->update($id, $request->validated())),
            resource: $this->service->label()
        );
    }

    /**
     * Create a real print-agent test job for the selected printer.
     *
     * @param int $id
     * @return JsonResponse
     */
    public function testPrint(int $id): JsonResponse
    {
        return ApiResponse::success(
            body: $this->service->testPrint($id)
        );
    }

    /**
     * This method deletes the Printer model based on the provided ids.
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
        return ApiResponse::success(
            $this->service->getFormMeta(
                auth()->user()->assignedToBranch()
                    ? auth()->user()->branch_id
                    : $request->get('branch_id')
            )
        );
    }
}
