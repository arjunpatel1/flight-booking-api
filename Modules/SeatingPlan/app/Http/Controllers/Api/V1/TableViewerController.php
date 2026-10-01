<?php

namespace Modules\SeatingPlan\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\Controller;
use Modules\SeatingPlan\Http\Requests\Api\V1\SaveTablePositionsRequest;
use Modules\SeatingPlan\Http\Requests\Api\V1\TableAssignWaiterRequest;
use Modules\SeatingPlan\Http\Requests\Api\V1\TableMergeRequest;
use Modules\SeatingPlan\Http\Requests\Api\V1\TableTransferRequest;
use Modules\SeatingPlan\Services\TableViewer\TableViewerServiceInterface;
use Modules\SeatingPlan\Transformers\Api\V1\TableShowViewerResource;
use Modules\SeatingPlan\Transformers\Api\V1\TableViewerResource;
use Modules\Support\ApiResponse;
use Modules\User\Enums\DefaultRole;
use Modules\User\Models\User;
use Throwable;

class TableViewerController extends Controller
{
    /**
     * Create a new instance of TableViewerController
     */
    public function __construct(protected TableViewerServiceInterface $service) {}

    /**
     * This method retrieves and returns a list of TableViewer models.
     */
    public function index(Request $request): JsonResponse
    {
        $user = auth()->user();
        $branchId = $user->assignedToBranch()
            ? $user->branch_id
            : $request->input('branch_id');

        $data = $this->service->get($branchId);

        return ApiResponse::success([
            'tables' => TableViewerResource::collection($data['tables']),
            'filters' => [
                'floors' => $data['floors'],
                'zones' => $data['zones'],
                'statuses' => $data['statuses'],
                'branches' => $data['branches'],
            ],
        ]);
    }

    /**
     * This method retrieves and returns a single Table model based on the provided identifier.
     */
    public function show(int $id): JsonResponse
    {
        $table = $this->service->show($id);

        return ApiResponse::success(
            body: [
                'table' => new TableShowViewerResource($table),
                'meta' => [
                    'waiters' => User::list($table->branch_id, DefaultRole::Waiter),
                ],
            ]
        );
    }

    /**
     * Table merged
     *
     * @throws Throwable
     */
    public function merge(TableMergeRequest $request, int $id): JsonResponse
    {
        $this->service->merge($id, $request->validated());

        return ApiResponse::success(message: __('seatingplan::messages.table_merge_successfully'));
    }

    /**
     * Transfer active table orders to another table.
     *
     * @throws Throwable
     */
    public function transfer(TableTransferRequest $request, int $id): JsonResponse
    {
        $this->service->transfer($id, $request->validated());

        return ApiResponse::success(message: __('seatingplan::messages.table_transfer_successfully'));
    }

    /**
     * Assign waiter
     */
    public function assignWaiter(TableAssignWaiterRequest $request, int $id): JsonResponse
    {
        $this->service->assignWaiter($id, $request->validated());

        return ApiResponse::success(message: __('seatingplan::messages.'.($request->waiter_id ? 'assigned_waiter_successfully' : 'unassigned_waiter_successfully')));
    }

    /**
     * Save table floor plan positions.
     */
    public function savePositions(SaveTablePositionsRequest $request): JsonResponse
    {
        $this->service->savePositions($request->validated('positions'));

        return ApiResponse::success(message: __('seatingplan::messages.table_positions_saved_successfully'));
    }

    /**
     * Make as available
     */
    public function makeAsAvailable(int $id): JsonResponse
    {
        $this->service->makeAsAvailable($id);

        return ApiResponse::success(message: __('seatingplan::messages.table_make_available_successfully'));
    }

    /**
     * Split table
     *
     * @throws Throwable
     */
    public function splitTable(int $id): JsonResponse
    {
        $this->service->splitTable($id);

        return ApiResponse::success(message: __('seatingplan::messages.split_success'));
    }

    /**
     * Get merge table meta
     */
    public function getMergeMeta(int $id): JsonResponse
    {
        return ApiResponse::success($this->service->getMergeMeta($id));
    }
}
