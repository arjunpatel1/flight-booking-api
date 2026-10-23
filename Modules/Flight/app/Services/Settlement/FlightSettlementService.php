<?php

namespace Modules\Flight\Services\Settlement;

use Carbon\Carbon;
use DomainException;
use Exception;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Flight\Enums\ReconciliationStatus;
use Modules\Flight\Enums\SettlementBatchStatus;
use Modules\Flight\Models\FlightBooking;
use Modules\Flight\Models\FlightSettlementAuditReport;
use Modules\Flight\Models\FlightSettlementBatch;
use Modules\Flight\Models\FlightSettlementItem;
use Modules\Flight\Models\FlightSettlementMismatch;

class FlightSettlementService
{
    public function __construct(
        protected FlightSettlementCalculationEngine $calculationEngine,
        protected FlightReconciliationEngine $reconciliationEngine
    ) {}

    /**
     * Create a new settlement batch for a designated period and filters.
     *
     * @param array $params
     * @param int|null $tenantId
     * @param int|null $userId
     * @return FlightSettlementBatch
     */
    public function createBatch(array $params, ?int $tenantId = null, ?int $userId = null): FlightSettlementBatch
    {
        $periodStart = Carbon::parse($params['period_start'] ?? now()->subDays(7)->startOfDay());
        $periodEnd   = Carbon::parse($params['period_end'] ?? now()->endOfDay());

        $supplierCode  = $params['supplier_code'] ?? null;
        $distributorId = isset($params['distributor_id']) ? (int) $params['distributor_id'] : null;
        $agentId       = isset($params['agent_id']) ? (int) $params['agent_id'] : null;

        return DB::transaction(function () use (
            $periodStart,
            $periodEnd,
            $supplierCode,
            $distributorId,
            $agentId,
            $tenantId,
            $userId,
            $params
        ) {
            // Find already settled booking IDs to avoid double settlement
            $settledBookingIds = FlightSettlementItem::whereHas('batch', function ($q) {
                $q->whereIn('status', [
                    SettlementBatchStatus::PENDING->value,
                    SettlementBatchStatus::PROCESSING->value,
                    SettlementBatchStatus::PROCESSED->value,
                    SettlementBatchStatus::RECONCILED->value,
                ]);
            })->pluck('flight_booking_id')->toArray();

            // Candidate bookings query
            $query = FlightBooking::query()
                ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
                ->whereNotIn('id', $settledBookingIds)
                ->where(function ($q) use ($periodStart, $periodEnd) {
                    $q->whereBetween('created_at', [$periodStart, $periodEnd])
                      ->orWhereBetween('confirmed_at', [$periodStart, $periodEnd]);
                });

            if ($supplierCode) {
                $query->where('supplier_code', $supplierCode);
            }

            if ($distributorId) {
                $query->where(function ($q) use ($distributorId) {
                    $q->where('meta->distributor_id', $distributorId)
                      ->orWhereHas('user', fn ($u) => $u->where('distributor_id', $distributorId));
                });
            }

            if ($agentId) {
                $query->where(function ($q) use ($agentId) {
                    $q->where('meta->agent_id', $agentId)
                      ->orWhere('user_id', $agentId);
                });
            }

            $bookings = $query->get();

            // Calculate financials for each candidate booking
            $calculatedItems = [];
            foreach ($bookings as $booking) {
                $calculatedItems[] = $this->calculationEngine->calculateForBooking($booking);
            }

            // Aggregate metrics
            $aggregates = $this->calculationEngine->aggregateTotals($calculatedItems);

            // Create batch
            $batch = FlightSettlementBatch::create([
                'tenant_id'                    => $tenantId,
                'period_start'                 => $periodStart,
                'period_end'                   => $periodEnd,
                'supplier_code'                => $supplierCode,
                'distributor_id'               => $distributorId,
                'agent_id'                     => $agentId,
                'status'                       => SettlementBatchStatus::PENDING,
                'reconciliation_status'        => ReconciliationStatus::PENDING,
                'total_bookings_count'         => $aggregates['total_bookings_count'],
                'total_booking_amount'         => $aggregates['total_booking_amount'],
                'total_supplier_payable'       => $aggregates['total_supplier_payable'],
                'total_platform_revenue'       => $aggregates['total_platform_revenue'],
                'total_tenant_revenue'         => $aggregates['total_tenant_revenue'],
                'total_distributor_commission' => $aggregates['total_distributor_commission'],
                'total_agent_commission'       => $aggregates['total_agent_commission'],
                'total_taxes'                  => $aggregates['total_taxes'],
                'total_fees'                   => $aggregates['total_fees'],
                'currency'                     => $params['currency'] ?? 'INR',
                'created_by'                   => $userId,
                'metadata'                     => [
                    'source_filters' => $params,
                    'created_via'    => 'settlement_workspace',
                ],
            ]);

            // Create line items
            foreach ($calculatedItems as $calc) {
                FlightSettlementItem::create([
                    'flight_settlement_batch_id' => $batch->id,
                    'tenant_id'                  => $tenantId ?? $calc['tenant_id'],
                    'flight_booking_id'          => $calc['booking_id'],
                    'booking_reference'          => $calc['booking_reference'],
                    'supplier_code'              => $calc['supplier_code'],
                    'distributor_id'             => $calc['distributor_id'],
                    'agent_id'                   => $calc['agent_id'],
                    'booking_amount'             => $calc['booking_amount'],
                    'supplier_payable'           => $calc['supplier_payable'],
                    'platform_revenue'           => $calc['platform_revenue'],
                    'tenant_revenue'             => $calc['tenant_revenue'],
                    'distributor_commission'     => $calc['distributor_commission'],
                    'agent_commission'           => $calc['agent_commission'],
                    'taxes'                      => $calc['taxes'],
                    'fees'                       => $calc['fees'],
                    'currency'                   => $calc['currency'],
                    'status'                     => 'PENDING',
                    'reconciliation_status'      => 'PENDING',
                    'calculation_breakdown'      => $calc,
                ]);
            }

            return $batch->fresh(['items']);
        });
    }

    /**
     * Process an existing settlement batch: Executes financial distribution & updates status.
     *
     * @param int $batchId
     * @param int|null $tenantId
     * @param int|null $userId
     * @return FlightSettlementBatch
     */
    public function processBatch(int $batchId, ?int $tenantId = null, ?int $userId = null): FlightSettlementBatch
    {
        $batch = $this->getBatchOrFail($batchId, $tenantId);

        if (! in_array($batch->status, [SettlementBatchStatus::PENDING, SettlementBatchStatus::FAILED])) {
            throw new DomainException("Cannot process batch in {$batch->status->value} status. Only PENDING or FAILED batches can be processed.");
        }

        try {
            DB::transaction(function () use ($batch, $userId) {
                $batch->markAsProcessing($userId);

                // Update items to PROCESSED
                FlightSettlementItem::where('flight_settlement_batch_id', $batch->id)
                    ->update(['status' => 'PROCESSED']);

                $batch->markAsProcessed($userId);
            });

            return $batch->fresh(['items']);
        } catch (Exception $e) {
            $batch->markAsFailed($e->getMessage());
            throw $e;
        }
    }

    /**
     * Retry a failed settlement batch.
     *
     * @param int $batchId
     * @param int|null $tenantId
     * @param int|null $userId
     * @return FlightSettlementBatch
     */
    public function retryBatch(int $batchId, ?int $tenantId = null, ?int $userId = null): FlightSettlementBatch
    {
        $batch = $this->getBatchOrFail($batchId, $tenantId);

        if ($batch->status !== SettlementBatchStatus::FAILED) {
            throw new DomainException("Only FAILED batches can be retried. Current status is {$batch->status->value}.");
        }

        return $this->processBatch($batchId, $tenantId, $userId);
    }

    /**
     * Reconcile a settlement batch: Executes 4-way reconciliation across Supplier, Gateway, Wallet & Booking.
     *
     * @param int $batchId
     * @param int|null $tenantId
     * @param int|null $userId
     * @return FlightSettlementBatch
     */
    public function reconcileBatch(int $batchId, ?int $tenantId = null, ?int $userId = null): FlightSettlementBatch
    {
        $batch = $this->getBatchOrFail($batchId, $tenantId);

        return DB::transaction(function () use ($batch, $tenantId, $userId) {
            $items = $batch->items()->with('booking')->get();
            $totalMismatches = 0;

            // Clear previous unresolved mismatches for this batch
            FlightSettlementMismatch::where('flight_settlement_batch_id', $batch->id)
                ->where('status', 'UNRESOLVED')
                ->delete();

            foreach ($items as $item) {
                $booking = $item->booking;
                if (! $booking) {
                    continue;
                }

                $calculated = $item->calculation_breakdown ?? $this->calculationEngine->calculateForBooking($booking);

                // Run 4-way reconciliation
                $reconResult = $this->reconciliationEngine->reconcileBooking($booking, $calculated);

                $itemHasMismatch = ! $reconResult['is_matched'];
                $itemStatus = $itemHasMismatch ? 'MISMATCH_DETECTED' : 'MATCHED';

                // Save mismatch records
                if ($itemHasMismatch) {
                    foreach ($reconResult['mismatches'] as $mm) {
                        FlightSettlementMismatch::create([
                            'flight_settlement_batch_id' => $batch->id,
                            'flight_settlement_item_id'  => $item->id,
                            'flight_booking_id'          => $booking->id,
                            'booking_reference'          => $booking->booking_reference,
                            'tenant_id'                  => $tenantId ?? $booking->tenant_id,
                            'mismatch_type'              => $mm['mismatch_type'],
                            'source_entity'              => $mm['source_entity'],
                            'expected_amount'            => $mm['expected_amount'],
                            'actual_amount'              => $mm['actual_amount'],
                            'discrepancy_amount'         => $mm['discrepancy_amount'],
                            'severity'                   => $mm['severity'],
                            'status'                     => 'UNRESOLVED',
                            'details'                    => $mm['details'],
                        ]);
                        $totalMismatches++;
                    }
                }

                $item->update([
                    'reconciliation_status'  => $itemStatus,
                    'has_mismatch'           => $itemHasMismatch,
                    'reconciliation_details' => $reconResult['reconciliation_details'],
                ]);
            }

            // Update batch reconciliation status
            $batch->mismatches_count = $totalMismatches;

            if ($totalMismatches === 0) {
                $batch->markAsReconciled(ReconciliationStatus::MATCHED);
            } else {
                $batch->reconciliation_status = ReconciliationStatus::MISMATCH_DETECTED;
                $batch->reconciled_at = now();
                $batch->save();
            }

            // Automatically create immutable audit report
            $this->generateAuditReport(
                $batch->id,
                $tenantId,
                $userId,
                "4-Way Reconciliation Report for {$batch->batch_reference}"
            );

            return $batch->fresh(['items', 'mismatches']);
        });
    }

    /**
     * Generate an immutable cryptographic audit report for a settlement batch.
     *
     * @param int $batchId
     * @param int|null $tenantId
     * @param int|null $userId
     * @param string $title
     * @return FlightSettlementAuditReport
     */
    public function generateAuditReport(
        int $batchId,
        ?int $tenantId = null,
        ?int $userId = null,
        string $title = 'Settlement Financial & Reconciliation Audit Report'
    ): FlightSettlementAuditReport {
        $batch = $this->getBatchOrFail($batchId, $tenantId);
        $mismatches = $batch->mismatches()->get();

        $summary = [
            'batch_reference'       => $batch->batch_reference,
            'period_start'          => $batch->period_start?->toIso8601String(),
            'period_end'            => $batch->period_end?->toIso8601String(),
            'supplier_code'         => $batch->supplier_code ?? 'ALL',
            'distributor_id'        => $batch->distributor_id,
            'agent_id'              => $batch->agent_id,
            'status'                => $batch->status->value,
            'reconciliation_status' => $batch->reconciliation_status->value,
            'total_bookings_count'  => $batch->total_bookings_count,
            'mismatches_count'      => $batch->mismatches_count,
            'match_rate_percentage' => $batch->total_bookings_count > 0
                ? round((($batch->total_bookings_count - $batch->mismatches_count) / $batch->total_bookings_count) * 100, 2)
                : 100.0,
            'currency'              => $batch->currency,
            'generated_at'          => now()->toIso8601String(),
        ];

        $financialBreakdown = [
            'total_booking_amount'         => (float) $batch->total_booking_amount,
            'total_supplier_payable'       => (float) $batch->total_supplier_payable,
            'total_platform_revenue'       => (float) $batch->total_platform_revenue,
            'total_tenant_revenue'         => (float) $batch->total_tenant_revenue,
            'total_distributor_commission' => (float) $batch->total_distributor_commission,
            'total_agent_commission'       => (float) $batch->total_agent_commission,
            'total_taxes'                  => (float) $batch->total_taxes,
            'total_fees'                   => (float) $batch->total_fees,
            'net_platform_retained'        => round($batch->total_platform_revenue + $batch->total_fees, 2),
        ];

        $findings = [
            'reconciliation_status' => $batch->reconciliation_status->value,
            'total_items_checked'   => $batch->total_bookings_count,
            'clean_matches_count'   => max(0, $batch->total_bookings_count - $batch->mismatches_count),
            'discrepancies_count'   => $batch->mismatches_count,
            'critical_count'        => $mismatches->where('severity', 'CRITICAL')->count(),
            'high_count'            => $mismatches->where('severity', 'HIGH')->count(),
            'medium_count'          => $mismatches->where('severity', 'MEDIUM')->count(),
            'low_count'             => $mismatches->where('severity', 'LOW')->count(),
        ];

        $mismatchRecords = $mismatches->map(fn ($m) => [
            'id'                 => $m->id,
            'booking_reference'  => $m->booking_reference,
            'mismatch_type'      => $m->mismatch_type,
            'source_entity'      => $m->source_entity,
            'expected_amount'    => (float) $m->expected_amount,
            'actual_amount'      => (float) $m->actual_amount,
            'discrepancy_amount' => (float) $m->discrepancy_amount,
            'severity'           => $m->severity,
            'status'             => $m->status,
            'details'            => $m->details,
        ])->toArray();

        return FlightSettlementAuditReport::create([
            'tenant_id'                  => $tenantId ?? $batch->tenant_id,
            'flight_settlement_batch_id' => $batch->id,
            'title'                      => $title,
            'report_type'                => 'SETTLEMENT_RECONCILIATION',
            'is_immutable'               => true,
            'summary'                    => $summary,
            'financial_breakdown'        => $financialBreakdown,
            'reconciliation_findings'    => $findings,
            'mismatch_records'           => $mismatchRecords,
            'generated_by'               => $userId,
            'generated_at'               => now(),
        ]);
    }

    /**
     * Get aggregate KPI metrics for the settlement workspace.
     */
    public function getWorkspaceKpis(?int $tenantId = null, array $filters = []): array
    {
        $query = FlightSettlementBatch::query()
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId));

        if (! empty($filters['start_date'])) {
            $query->where('period_start', '>=', Carbon::parse($filters['start_date']));
        }
        if (! empty($filters['end_date'])) {
            $query->where('period_end', '<=', Carbon::parse($filters['end_date']));
        }
        if (! empty($filters['supplier_code'])) {
            $query->where('supplier_code', $filters['supplier_code']);
        }
        if (! empty($filters['distributor_id'])) {
            $query->where('distributor_id', $filters['distributor_id']);
        }
        if (! empty($filters['agent_id'])) {
            $query->where('agent_id', $filters['agent_id']);
        }

        $batches = $query->get();

        $totalPayable       = 0.0;
        $totalRevenue       = 0.0;
        $totalTenantRevenue = 0.0;
        $totalDistComm      = 0.0;
        $totalAgentComm     = 0.0;
        $totalTaxes         = 0.0;
        $totalFees          = 0.0;
        $totalBookings      = 0;
        $totalMismatches    = 0;

        $statusCounts = [
            'PENDING'    => 0,
            'PROCESSING' => 0,
            'PROCESSED'  => 0,
            'FAILED'     => 0,
            'RECONCILED' => 0,
        ];

        foreach ($batches as $b) {
            $totalPayable       += (float) $b->total_supplier_payable;
            $totalRevenue       += (float) $b->total_platform_revenue;
            $totalTenantRevenue += (float) $b->total_tenant_revenue;
            $totalDistComm      += (float) $b->total_distributor_commission;
            $totalAgentComm     += (float) $b->total_agent_commission;
            $totalTaxes         += (float) $b->total_taxes;
            $totalFees          += (float) $b->total_fees;
            $totalBookings      += (int) $b->total_bookings_count;
            $totalMismatches    += (int) $b->mismatches_count;

            $st = $b->status instanceof SettlementBatchStatus ? $b->status->value : (string) $b->status;
            if (isset($statusCounts[$st])) {
                $statusCounts[$st]++;
            }
        }

        $matchRate = $totalBookings > 0
            ? round((($totalBookings - $totalMismatches) / $totalBookings) * 100, 1)
            : 100.0;

        return [
            'total_supplier_payable'       => round($totalPayable, 2),
            'total_platform_revenue'       => round($totalRevenue, 2),
            'total_tenant_revenue'         => round($totalTenantRevenue, 2),
            'total_distributor_commission' => round($totalDistComm, 2),
            'total_agent_commission'       => round($totalAgentComm, 2),
            'total_commissions'            => round($totalDistComm + $totalAgentComm, 2),
            'total_taxes'                  => round($totalTaxes, 2),
            'total_fees'                   => round($totalFees, 2),
            'total_batches_count'          => count($batches),
            'total_bookings_count'         => $totalBookings,
            'total_mismatches_count'       => $totalMismatches,
            'reconciliation_match_rate'    => $matchRate,
            'status_counts'                => $statusCounts,
            'currency'                     => 'INR',
        ];
    }

    /**
     * List settlement batches with filtering and pagination.
     */
    public function listBatches(array $filters = [], ?int $tenantId = null, int $perPage = 15): LengthAwarePaginator
    {
        $query = FlightSettlementBatch::query()
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId));

        if (! empty($filters['status']) && $filters['status'] !== 'ALL') {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['reconciliation_status']) && $filters['reconciliation_status'] !== 'ALL') {
            $query->where('reconciliation_status', $filters['reconciliation_status']);
        }
        if (! empty($filters['supplier_code'])) {
            $query->where('supplier_code', $filters['supplier_code']);
        }
        if (! empty($filters['distributor_id'])) {
            $query->where('distributor_id', $filters['distributor_id']);
        }
        if (! empty($filters['agent_id'])) {
            $query->where('agent_id', $filters['agent_id']);
        }
        if (! empty($filters['start_date'])) {
            $query->where('period_start', '>=', Carbon::parse($filters['start_date']));
        }
        if (! empty($filters['end_date'])) {
            $query->where('period_end', '<=', Carbon::parse($filters['end_date']));
        }
        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where('batch_reference', 'LIKE', "%{$search}%");
        }

        return $query->latest('id')->paginate($perPage);
    }

    /**
     * Get single batch details with items and mismatches.
     */
    public function getBatchOrFail(int $batchId, ?int $tenantId = null): FlightSettlementBatch
    {
        $query = FlightSettlementBatch::query();
        if ($tenantId !== null) {
            $query->where('tenant_id', $tenantId);
        }

        return $query->with(['items.booking', 'mismatches'])->findOrFail($batchId);
    }

    /**
     * List detected mismatches.
     */
    public function listMismatches(array $filters = [], ?int $tenantId = null, int $perPage = 20): LengthAwarePaginator
    {
        $query = FlightSettlementMismatch::query()
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId));

        if (! empty($filters['batch_id'])) {
            $query->where('flight_settlement_batch_id', $filters['batch_id']);
        }
        if (! empty($filters['status']) && $filters['status'] !== 'ALL') {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['severity']) && $filters['severity'] !== 'ALL') {
            $query->where('severity', $filters['severity']);
        }
        if (! empty($filters['mismatch_type']) && $filters['mismatch_type'] !== 'ALL') {
            $query->where('mismatch_type', $filters['mismatch_type']);
        }

        return $query->latest('id')->paginate($perPage);
    }

    /**
     * Resolve a detected discrepancy mismatch.
     */
    public function resolveMismatch(int $mismatchId, int $userId, string $notes, ?int $tenantId = null): FlightSettlementMismatch
    {
        $query = FlightSettlementMismatch::query()
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId));

        $mismatch = $query->findOrFail($mismatchId);
        $mismatch->resolve($userId, $notes);

        // Update parent batch mismatch count
        $unresolvedCount = FlightSettlementMismatch::where('flight_settlement_batch_id', $mismatch->flight_settlement_batch_id)
            ->where('status', 'UNRESOLVED')
            ->count();

        $batch = FlightSettlementBatch::find($mismatch->flight_settlement_batch_id);
        if ($batch) {
            $batch->mismatches_count = $unresolvedCount;
            if ($unresolvedCount === 0) {
                $batch->reconciliation_status = ReconciliationStatus::MATCHED;
            }
            $batch->save();
        }

        return $mismatch;
    }

    /**
     * List audit reports.
     */
    public function listAuditReports(array $filters = [], ?int $tenantId = null, int $perPage = 15): LengthAwarePaginator
    {
        $query = FlightSettlementAuditReport::query()
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId));

        if (! empty($filters['batch_id'])) {
            $query->where('flight_settlement_batch_id', $filters['batch_id']);
        }

        return $query->latest('id')->paginate($perPage);
    }

    /**
     * Get single audit report with cryptographic checksum verification.
     */
    public function getAuditReport(int $reportId, ?int $tenantId = null): array
    {
        $query = FlightSettlementAuditReport::query()
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId));

        $report = $query->findOrFail($reportId);

        return [
            'report'           => $report,
            'is_valid'         => $report->verifyChecksum(),
            'checksum_sha256'  => $report->checksum_sha256,
            'tamper_evident'   => true,
        ];
    }
}
