<?php

namespace Modules\Setting\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\Controller;
use Modules\Setting\Http\Requests\Api\V1\RestoreSystemBackupRequest;
use Modules\Setting\Models\SystemBackup;
use Modules\Setting\Services\SystemBackup\SystemBackupServiceInterface;
use Modules\Setting\Transformers\Api\V1\SystemBackupResource;
use Modules\Setting\Transformers\Api\V1\SystemRestoreResource;
use Modules\Support\ApiResponse;

class SystemBackupController extends Controller
{
    public function __construct(private readonly SystemBackupServiceInterface $service)
    {
    }

    public function index(Request $request): JsonResponse
    {
        return ApiResponse::pagination(
            paginator: $this->service->get(
                filters: $request->get('filters', []),
                sorts: $request->get('sorts', [])
            ),
            resource: SystemBackupResource::class
        );
    }

    public function store(): JsonResponse
    {
        return ApiResponse::success([
            'backup' => SystemBackupResource::make($this->service->createDatabaseBackup()),
        ]);
    }

    public function restores(Request $request): JsonResponse
    {
        return ApiResponse::pagination(
            paginator: $this->service->getRestores(
                filters: $request->get('filters', []),
                sorts: $request->get('sorts', [])
            ),
            resource: SystemRestoreResource::class
        );
    }

    public function restore(RestoreSystemBackupRequest $request, SystemBackup $systemBackup): JsonResponse
    {
        return ApiResponse::success([
            'restore' => SystemRestoreResource::make(
                $this->service->restoreDatabaseBackup($systemBackup, $request->validated('reason'))
            ),
        ]);
    }
}
