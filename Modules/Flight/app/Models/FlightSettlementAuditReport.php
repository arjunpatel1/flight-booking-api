<?php

namespace Modules\Flight\Models;

use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Modules\Saas\Models\Tenant;
use Modules\Saas\Traits\BelongsToTenant;
use Modules\Support\Eloquent\Model;
use Modules\User\Models\User;

class FlightSettlementAuditReport extends Model
{
    use BelongsToTenant;

    protected $table = 'flight_settlement_audit_reports';

    protected $fillable = [
        'tenant_id',
        'flight_settlement_batch_id',
        'report_reference',
        'title',
        'report_type',
        'checksum_sha256',
        'is_immutable',
        'summary',
        'financial_breakdown',
        'reconciliation_findings',
        'mismatch_records',
        'generated_by',
        'generated_at',
    ];

    protected $casts = [
        'is_immutable'            => 'boolean',
        'summary'                 => 'array',
        'financial_breakdown'     => 'array',
        'reconciliation_findings' => 'array',
        'mismatch_records'        => 'array',
        'generated_at'            => 'datetime',
    ];

    /**
     * Enforce strict immutability: Reports cannot be altered or deleted once generated.
     */
    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->report_reference)) {
                $model->report_reference = 'AUDIT-SETTLE-' . date('Ymd') . '-' . strtoupper(Str::random(8));
            }
            if (empty($model->generated_at)) {
                $model->generated_at = now();
            }
            if (empty($model->checksum_sha256)) {
                $model->checksum_sha256 = self::computeChecksum(
                    $model->summary ?? [],
                    $model->financial_breakdown ?? [],
                    $model->reconciliation_findings ?? [],
                    $model->mismatch_records ?? []
                );
            }
        });

        static::updating(function () {
            throw new DomainException('Settlement audit reports are strictly immutable and cannot be updated.');
        });

        static::deleting(function () {
            throw new DomainException('Settlement audit reports are strictly immutable and cannot be deleted.');
        });
    }

    /**
     * Compute deterministic cryptographic SHA-256 checksum for audit report payload.
     */
    public static function computeChecksum(
        array $summary,
        array $financialBreakdown,
        array $findings,
        array $mismatchRecords
    ): string {
        $payload = [
            'summary'             => $summary,
            'financial_breakdown' => $financialBreakdown,
            'findings'            => $findings,
            'mismatch_records'    => $mismatchRecords,
        ];

        return hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Verify whether the report payload matches its tamper-evident cryptographic checksum.
     */
    public function verifyChecksum(): bool
    {
        $currentHash = self::computeChecksum(
            $this->summary ?? [],
            $this->financial_breakdown ?? [],
            $this->reconciliation_findings ?? [],
            $this->mismatch_records ?? []
        );

        return hash_equals($this->checksum_sha256, $currentHash);
    }

    // ── Relationships ─────────────────────────────────────────────────────────

    public function batch(): BelongsTo
    {
        return $this->belongsTo(FlightSettlementBatch::class, 'flight_settlement_batch_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeForBatch(Builder $query, int $batchId): void
    {
        $query->where('flight_settlement_batch_id', $batchId);
    }
}
