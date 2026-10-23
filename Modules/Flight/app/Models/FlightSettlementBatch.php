<?php

namespace Modules\Flight\Models;

use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Modules\Flight\Enums\ReconciliationStatus;
use Modules\Flight\Enums\SettlementBatchStatus;
use Modules\Saas\Models\Tenant;
use Modules\Saas\Traits\BelongsToTenant;
use Modules\Support\Eloquent\Model;
use Modules\User\Models\User;

class FlightSettlementBatch extends Model
{
    use BelongsToTenant;

    protected $table = 'flight_settlement_batches';

    protected $fillable = [
        'tenant_id',
        'batch_reference',
        'period_start',
        'period_end',
        'supplier_code',
        'distributor_id',
        'agent_id',
        'status',
        'reconciliation_status',
        'total_bookings_count',
        'total_booking_amount',
        'total_supplier_payable',
        'total_platform_revenue',
        'total_tenant_revenue',
        'total_distributor_commission',
        'total_agent_commission',
        'total_taxes',
        'total_fees',
        'currency',
        'mismatches_count',
        'processed_at',
        'reconciled_at',
        'failed_at',
        'failure_reason',
        'created_by',
        'processed_by',
        'metadata',
    ];

    protected $casts = [
        'period_start'                 => 'datetime',
        'period_end'                   => 'datetime',
        'processed_at'                 => 'datetime',
        'reconciled_at'                => 'datetime',
        'failed_at'                    => 'datetime',
        'status'                       => SettlementBatchStatus::class,
        'reconciliation_status'        => ReconciliationStatus::class,
        'total_bookings_count'         => 'integer',
        'mismatches_count'             => 'integer',
        'total_booking_amount'         => 'decimal:2',
        'total_supplier_payable'       => 'decimal:2',
        'total_platform_revenue'       => 'decimal:2',
        'total_tenant_revenue'         => 'decimal:2',
        'total_distributor_commission' => 'decimal:2',
        'total_agent_commission'       => 'decimal:2',
        'total_taxes'                  => 'decimal:2',
        'total_fees'                   => 'decimal:2',
        'metadata'                     => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->batch_reference)) {
                $model->batch_reference = 'SETTLE-' . date('Ymd') . '-' . strtoupper(Str::random(6));
            }
        });
    }

    // ── Relationships ─────────────────────────────────────────────────────────

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(FlightSettlementItem::class, 'flight_settlement_batch_id');
    }

    public function mismatches(): HasMany
    {
        return $this->hasMany(FlightSettlementMismatch::class, 'flight_settlement_batch_id');
    }

    public function auditReports(): HasMany
    {
        return $this->hasMany(FlightSettlementAuditReport::class, 'flight_settlement_batch_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function processedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeForTenant(Builder $query, ?int $tenantId): void
    {
        if ($tenantId !== null) {
            $query->where('tenant_id', $tenantId);
        }
    }

    public function scopeByStatus(Builder $query, string|SettlementBatchStatus $status): void
    {
        $statusValue = $status instanceof SettlementBatchStatus ? $status->value : $status;
        $query->where('status', $statusValue);
    }

    public function scopeInDateRange(Builder $query, ?string $start, ?string $end): void
    {
        if ($start) {
            $query->where('period_start', '>=', $start);
        }
        if ($end) {
            $query->where('period_end', '<=', $end);
        }
    }

    // ── Lifecycle Transitions ─────────────────────────────────────────────────

    public function canTransitionTo(SettlementBatchStatus $target): bool
    {
        $current = $this->status instanceof SettlementBatchStatus
            ? $this->status
            : SettlementBatchStatus::from((string) $this->status);

        return $current->canTransitionTo($target);
    }

    public function markAsProcessing(?int $userId = null): void
    {
        $this->status = SettlementBatchStatus::PROCESSING;
        if ($userId) {
            $this->processed_by = $userId;
        }
        $this->save();
    }

    public function markAsProcessed(?int $userId = null): void
    {
        $this->status = SettlementBatchStatus::PROCESSED;
        $this->processed_at = now();
        $this->failed_at = null;
        $this->failure_reason = null;
        if ($userId) {
            $this->processed_by = $userId;
        }
        $this->save();
    }

    public function markAsFailed(string $reason): void
    {
        $this->status = SettlementBatchStatus::FAILED;
        $this->failed_at = now();
        $this->failure_reason = $reason;
        $this->save();
    }

    public function markAsReconciled(ReconciliationStatus $reconStatus = ReconciliationStatus::RECONCILED): void
    {
        $this->status = SettlementBatchStatus::RECONCILED;
        $this->reconciliation_status = $reconStatus;
        $this->reconciled_at = now();
        $this->save();
    }
}
