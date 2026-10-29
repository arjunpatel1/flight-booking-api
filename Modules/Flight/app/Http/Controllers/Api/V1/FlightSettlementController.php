<?php

namespace Modules\Flight\Http\Controllers\Api\V1;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\Controller;
use Modules\Flight\Services\Settlement\FlightSettlementService;
use Modules\Support\ApiResponse;
use Modules\User\Enums\DefaultRole;

class FlightSettlementController extends Controller
{
    public function __construct(
        protected FlightSettlementService $settlementService
    ) {}

    protected function resolveTenantId(Request $request): ?int
    {
        return $request->user()?->tenant_id ?? auth()->user()?->tenant_id;
    }

    /**
     * Enforce role-based access for financial actions:
     * Only platform admins, the agency's Flight Admin, a finance role, or users
     * granted 'flight_settlements.edit' can execute financial actions.
     */
    protected function authorizeFinancialAction(Request $request): void
    {
        $user = $request->user();
        if (! $user) {
            return;
        }

        $isAuthorized = false;
        if (method_exists($user, 'hasAnyRole')) {
            // The real role names (DefaultRole) use underscores; the hyphenated
            // 'super-admin' / 'agency-admin' never existed, so Flight Admins
            // and super admins were refused.
            $isAuthorized = $user->hasAnyRole([
                DefaultRole::SuperAdmin->value,
                DefaultRole::Admin->value,
                DefaultRole::FlightAdmin->value,
                'finance',
            ]);
        } elseif (method_exists($user, 'hasRole')) {
            $isAuthorized = $user->hasRole('admin') || $user->hasRole('finance');
        }

        if (! $isAuthorized && method_exists($user, 'can')) {
            $isAuthorized = $user->can('flight_settlements.edit') || $user->can('flight.settlement.manage');
        }

        if (! $isAuthorized) {
            abort(403, 'Unauthorized. Financial settlement actions require an authorized Finance or Admin role.');
        }
    }

    /**
     * Workspace KPI Summary
     * GET /api/v1/flight/settlements/workspace
     */
    public function workspace(Request $request): JsonResponse
    {
        $tenantId = $this->resolveTenantId($request);
        $kpis = $this->settlementService->getWorkspaceKpis($tenantId, $request->all());

        return ApiResponse::success($kpis, 'Settlement workspace KPIs retrieved successfully');
    }

    /**
     * List Settlement Batches
     * GET /api/v1/flight/settlements/batches
     */
    public function indexBatches(Request $request): JsonResponse
    {
        $tenantId = $this->resolveTenantId($request);
        $batches = $this->settlementService->listBatches(
            $request->all(),
            $tenantId,
            $request->integer('per_page', 15)
        );

        return ApiResponse::pagination($batches);
    }

    /**
     * Create Settlement Batch
     * POST /api/v1/flight/settlements/batches
     */
    public function createBatch(Request $request): JsonResponse
    {
        $this->authorizeFinancialAction($request);

        $validated = $request->validate([
            'period_start'   => 'nullable|date',
            'period_end'     => 'nullable|date',
            'supplier_code'  => 'nullable|string|max:50',
            'distributor_id' => 'nullable|integer',
            'agent_id'       => 'nullable|integer',
            'currency'       => 'nullable|string|size:3',
        ]);

        $tenantId = $this->resolveTenantId($request);
        $userId   = $request->user()?->id;

        try {
            $batch = $this->settlementService->createBatch($validated, $tenantId, $userId);

            return ApiResponse::success($batch, 'Settlement batch created successfully', 201);
        } catch (Exception $e) {
            return ApiResponse::errors(null, 'Failed to create settlement batch: ' . $e->getMessage(), 422);
        }
    }

    /**
     * Get Settlement Batch Details
     * GET /api/v1/flight/settlements/batches/{id}
     */
    public function showBatch(Request $request, int $id): JsonResponse
    {
        $tenantId = $this->resolveTenantId($request);

        try {
            $batch = $this->settlementService->getBatchOrFail($id, $tenantId);
            return ApiResponse::success($batch, 'Settlement batch details retrieved');
        } catch (Exception $e) {
            return ApiResponse::errors(null, 'Settlement batch not found', 404);
        }
    }

    /**
     * Process Settlement Batch
     * POST /api/v1/flight/settlements/batches/{id}/process
     */
    public function processBatch(Request $request, int $id): JsonResponse
    {
        $this->authorizeFinancialAction($request);

        $tenantId = $this->resolveTenantId($request);
        $userId   = $request->user()?->id;

        try {
            $batch = $this->settlementService->processBatch($id, $tenantId, $userId);
            return ApiResponse::success($batch, 'Settlement batch processed successfully');
        } catch (Exception $e) {
            return ApiResponse::errors(null, 'Failed to process settlement batch: ' . $e->getMessage(), 422);
        }
    }

    /**
     * Retry Failed Settlement Batch
     * POST /api/v1/flight/settlements/batches/{id}/retry
     */
    public function retryBatch(Request $request, int $id): JsonResponse
    {
        $this->authorizeFinancialAction($request);

        $tenantId = $this->resolveTenantId($request);
        $userId   = $request->user()?->id;

        try {
            $batch = $this->settlementService->retryBatch($id, $tenantId, $userId);
            return ApiResponse::success($batch, 'Settlement batch retried successfully');
        } catch (Exception $e) {
            return ApiResponse::errors(null, 'Failed to retry settlement batch: ' . $e->getMessage(), 422);
        }
    }

    /**
     * Execute 4-Way Reconciliation
     * POST /api/v1/flight/settlements/batches/{id}/reconcile
     */
    public function reconcileBatch(Request $request, int $id): JsonResponse
    {
        $this->authorizeFinancialAction($request);

        $tenantId = $this->resolveTenantId($request);
        $userId   = $request->user()?->id;

        try {
            $batch = $this->settlementService->reconcileBatch($id, $tenantId, $userId);
            return ApiResponse::success($batch, '4-Way reconciliation completed successfully');
        } catch (Exception $e) {
            return ApiResponse::errors(null, 'Reconciliation failed: ' . $e->getMessage(), 422);
        }
    }

    /**
     * Generate Immutable Audit Report
     * POST /api/v1/flight/settlements/batches/{id}/audit-report
     */
    public function generateAuditReport(Request $request, int $id): JsonResponse
    {
        $this->authorizeFinancialAction($request);

        $tenantId = $this->resolveTenantId($request);
        $userId   = $request->user()?->id;
        $title    = $request->input('title', 'Settlement Financial & Reconciliation Audit Report');

        try {
            $report = $this->settlementService->generateAuditReport($id, $tenantId, $userId, $title);
            return ApiResponse::success([
                'report'          => $report,
                'checksum_sha256' => $report->checksum_sha256,
                'is_immutable'    => true,
            ], 'Immutable audit report generated successfully', 201);
        } catch (Exception $e) {
            return ApiResponse::errors(null, 'Failed to generate audit report: ' . $e->getMessage(), 422);
        }
    }

    /**
     * List Detected Mismatches
     * GET /api/v1/flight/settlements/mismatches
     */
    public function indexMismatches(Request $request): JsonResponse
    {
        $tenantId = $this->resolveTenantId($request);
        $mismatches = $this->settlementService->listMismatches(
            $request->all(),
            $tenantId,
            $request->integer('per_page', 20)
        );

        return ApiResponse::pagination($mismatches);
    }

    /**
     * Resolve Detected Mismatch
     * POST /api/v1/flight/settlements/mismatches/{id}/resolve
     */
    public function resolveMismatch(Request $request, int $id): JsonResponse
    {
        $this->authorizeFinancialAction($request);

        $request->validate([
            'notes' => 'required|string|max:1000',
        ]);

        $tenantId = $this->resolveTenantId($request);
        $userId   = $request->user()?->id ?? 1;

        try {
            $mismatch = $this->settlementService->resolveMismatch($id, $userId, $request->input('notes'), $tenantId);
            return ApiResponse::success($mismatch, 'Discrepancy resolved successfully');
        } catch (Exception $e) {
            return ApiResponse::errors(null, 'Failed to resolve mismatch: ' . $e->getMessage(), 422);
        }
    }

    /**
     * List Immutable Audit Reports
     * GET /api/v1/flight/settlements/audit-reports
     */
    public function indexAuditReports(Request $request): JsonResponse
    {
        $tenantId = $this->resolveTenantId($request);
        $reports = $this->settlementService->listAuditReports(
            $request->all(),
            $tenantId,
            $request->integer('per_page', 15)
        );

        return ApiResponse::pagination($reports);
    }

    /**
     * Show Audit Report with Cryptographic Verification
     * GET /api/v1/flight/settlements/audit-reports/{id}
     */
    public function showAuditReport(Request $request, int $id): JsonResponse
    {
        $tenantId = $this->resolveTenantId($request);

        try {
            $result = $this->settlementService->getAuditReport($id, $tenantId);
            return ApiResponse::success($result, 'Audit report retrieved and cryptographically verified');
        } catch (Exception $e) {
            return ApiResponse::errors(null, 'Audit report not found', 404);
        }
    }
}
