<?php

namespace Modules\ActivityLog\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\ActivityLog\Services\ActivityLog\ActivityLogServiceInterface;
use Modules\ActivityLog\Transformers\Api\V1\ActivityLogResource;
use Modules\ActivityLog\Transformers\Api\V1\ActivityLogShowResource;
use Modules\Core\Http\Controllers\Controller;
use Modules\Support\ApiResponse;

class ActivityLogController extends Controller
{
    /**
     * Create a new instance of ActivityLogController
     *
     * @param ActivityLogServiceInterface $service
     */
    public function __construct(protected ActivityLogServiceInterface $service)
    {
    }

    /**
     * Display a listing of the resource.
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
            resource: ActivityLogResource::class,
            filters: $request->get('with_filters')
                ? $this->service->getStructureFilters()
                : null
        );
    }

    /**
     * Show the specified resource.
     *
     * @param int $id
     * @return JsonResponse
     */
    public function show(int $id): JsonResponse
    {
        return ApiResponse::success(
            body: new ActivityLogShowResource($this->service->show($id))
        );
    }

    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate([
            'retention_days' => ['required', 'integer', 'in:30,60,90,180,365'],
            'confirmation' => ['required', 'string', 'in:CLEAR LOGS'],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);

        $deleted = $this->service->prune((int) $data['retention_days']);
        activity('activity_log')->event('audit_logs_pruned')->causedBy($request->user())
            ->withProperties(['retention_days' => (int) $data['retention_days'], 'deleted_count' => $deleted, 'reason' => $data['reason']])
            ->log('Expired tenant audit logs were removed under the retention policy.');

        return ApiResponse::success(['deleted_count' => $deleted], "$deleted expired audit records removed.");
    }
}
