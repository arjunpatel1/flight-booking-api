<?php

namespace Modules\Saas\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Saas\Enums\CustomerLifecycleStage;
use Modules\Saas\Enums\OnboardingRequestStatus;
use Modules\Support\Eloquent\Model;
use Modules\User\Models\User;

/**
 * A customer's intent to onboard, from first contact through to a provisioned
 * tenant. See the migration for why this sits between payment and provisioning.
 *
 * @property OnboardingRequestStatus $status
 * @property string $approval_mode
 * @property string $payment_status
 */
class SaasOnboardingRequest extends Model
{
    public const APPROVAL_AUTO = 'auto';
    public const APPROVAL_MANUAL = 'manual';
    public const APPROVAL_SALES = 'sales';

    public const PAYMENT_NOT_REQUIRED = 'not_required';
    public const PAYMENT_PENDING = 'pending';
    public const PAYMENT_PAID = 'paid';
    public const PAYMENT_FAILED = 'failed';
    public const PAYMENT_REFUNDED = 'refunded';

    public static function approvalModes(): array
    {
        return [self::APPROVAL_AUTO, self::APPROVAL_MANUAL, self::APPROVAL_SALES];
    }

    protected $fillable = [
        'uuid', 'source', 'approval_mode', 'status',
        'restaurant_name', 'slug', 'domain', 'contact_name', 'email', 'phone', 'payload',
        'plan_code', 'trial_days',
        'payment_status', 'payment_gateway', 'payment_reference', 'amount', 'currency', 'paid_at',
        'approved_by', 'approved_at', 'decision_reason',
        'assigned_to', 'assigned_at',
        'tenant_id', 'provisioning_run_id', 'onboarding_invite_id',
        'attempts', 'failure_reason', 'failed_at', 'completed_at',
        'timeline', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => OnboardingRequestStatus::class,
            'payload' => 'array',
            'timeline' => 'array',
            'amount' => 'decimal:2',
            'trial_days' => 'integer',
            'attempts' => 'integer',
            'paid_at' => 'datetime',
            'approved_at' => 'datetime',
            'assigned_at' => 'datetime',
            'failed_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function provisioningRun(): BelongsTo
    {
        return $this->belongsTo(SaasProvisioningRun::class, 'provisioning_run_id');
    }

    public function invite(): BelongsTo
    {
        return $this->belongsTo(SaasOnboardingInvite::class, 'onboarding_invite_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeAwaitingOperator(Builder $query): void
    {
        $query->whereIn('status', [
            OnboardingRequestStatus::AwaitingApproval->value,
            OnboardingRequestStatus::Failed->value,
        ]);
    }

    public function scopeOpen(Builder $query): void
    {
        $query->whereNotIn('status', [
            OnboardingRequestStatus::Completed->value,
            OnboardingRequestStatus::Rejected->value,
            OnboardingRequestStatus::Cancelled->value,
        ]);
    }

    public function isPaid(): bool
    {
        return in_array($this->payment_status, [self::PAYMENT_PAID, self::PAYMENT_NOT_REQUIRED], true);
    }

    /**
     * Auto-provision only when nobody needs to look at it first. Sales and
     * manual both stop for a human; they differ in who is expected to act,
     * which drives queue filtering in the console.
     */
    public function isAutoApproved(): bool
    {
        return $this->approval_mode === self::APPROVAL_AUTO;
    }

    /**
     * The commercial stage this request represents, so the lifecycle board can
     * show pre-tenant customers alongside provisioned ones.
     */
    public function lifecycleStage(): CustomerLifecycleStage
    {
        return match ($this->status) {
            OnboardingRequestStatus::Draft => CustomerLifecycleStage::Lead,
            OnboardingRequestStatus::AwaitingPayment => CustomerLifecycleStage::PaymentPending,
            OnboardingRequestStatus::AwaitingApproval,
            OnboardingRequestStatus::Approved => CustomerLifecycleStage::Paid,
            OnboardingRequestStatus::Provisioning => CustomerLifecycleStage::Provisioning,
            OnboardingRequestStatus::Completed => CustomerLifecycleStage::Onboarding,
            OnboardingRequestStatus::Rejected,
            OnboardingRequestStatus::Cancelled => CustomerLifecycleStage::Cancelled,
            OnboardingRequestStatus::Failed => CustomerLifecycleStage::Provisioning,
        };
    }

    /**
     * Append an auditable event. The timeline is the operator's answer to
     * "what happened to this request and when" without reading logs.
     */
    public function recordEvent(string $event, string $message, array $context = []): void
    {
        $timeline = $this->timeline ?? [];
        $timeline[] = [
            'event' => $event,
            'message' => $message,
            'context' => $context,
            'at' => now()->toIso8601String(),
        ];

        // Bounded: a request retried many times must not grow without limit.
        $this->forceFill(['timeline' => array_slice($timeline, -100)])->saveQuietly();
    }
}
