<?php

namespace Modules\Printer\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\Controller;
use Modules\Printer\Http\Requests\Api\V1\SavePrinterAssignmentsRequest;
use Modules\Printer\Services\PrinterAssignment\PrinterAssignmentService;
use Modules\Support\ApiResponse;

class PrinterAssignmentController extends Controller
{
    public function __construct(protected PrinterAssignmentService $service)
    {
    }

    public function show(Request $request): JsonResponse
    {
        $branchId = $this->service->resolveBranchId((int) $request->get('branch_id'));

        return ApiResponse::success([
            'assignments' => $this->service->show($branchId),
            'meta' => $this->service->meta($branchId),
        ]);
    }

    public function update(SavePrinterAssignmentsRequest $request): JsonResponse
    {
        return ApiResponse::updated(
            body: $this->service->save($request->validated()),
            resource: __('printer::printers.printer_assignments')
        );
    }
}
