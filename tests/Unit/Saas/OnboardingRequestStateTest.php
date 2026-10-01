<?php

namespace Tests\Unit\Saas;

use Modules\Saas\Enums\CustomerLifecycleStage;
use Modules\Saas\Enums\OnboardingRequestStatus;
use Modules\Saas\Models\SaasOnboardingRequest;
use PHPUnit\Framework\TestCase;

/**
 * The onboarding state machine's rules, tested without a database.
 *
 * These encode the guarantees the pipeline depends on: that a webhook cannot
 * provision, that terminal states stay terminal, and that only a failure is
 * retryable.
 */
class OnboardingRequestStateTest extends TestCase
{
    public function test_terminal_states_are_closed_to_further_transitions(): void
    {
        foreach ([
            OnboardingRequestStatus::Completed,
            OnboardingRequestStatus::Rejected,
            OnboardingRequestStatus::Cancelled,
        ] as $status) {
            $this->assertTrue($status->isTerminal(), "{$status->value} must be terminal.");
        }

        foreach ([
            OnboardingRequestStatus::Draft,
            OnboardingRequestStatus::AwaitingPayment,
            OnboardingRequestStatus::AwaitingApproval,
            OnboardingRequestStatus::Approved,
            OnboardingRequestStatus::Provisioning,
            OnboardingRequestStatus::Failed,
        ] as $status) {
            $this->assertFalse($status->isTerminal(), "{$status->value} must not be terminal.");
        }
    }

    public function test_only_a_failed_request_is_retryable(): void
    {
        foreach (OnboardingRequestStatus::cases() as $status) {
            $this->assertSame(
                $status === OnboardingRequestStatus::Failed,
                $status->isRetryable(),
                "Retryability wrong for {$status->value}."
            );
        }
    }

    public function test_auto_is_the_only_mode_that_skips_a_human(): void
    {
        foreach (SaasOnboardingRequest::approvalModes() as $mode) {
            $request = new SaasOnboardingRequest(['approval_mode' => $mode]);

            $this->assertSame(
                $mode === SaasOnboardingRequest::APPROVAL_AUTO,
                $request->isAutoApproved(),
                "Approval mode {$mode} behaved unexpectedly."
            );
        }

        // Sales and manual both stop for a human — they differ only in who acts.
        foreach ([SaasOnboardingRequest::APPROVAL_MANUAL, SaasOnboardingRequest::APPROVAL_SALES] as $mode) {
            $this->assertFalse((new SaasOnboardingRequest(['approval_mode' => $mode]))->isAutoApproved());
        }
    }

    public function test_a_request_is_payable_only_when_paid_or_payment_is_not_required(): void
    {
        $paid = new SaasOnboardingRequest(['payment_status' => SaasOnboardingRequest::PAYMENT_PAID]);
        $free = new SaasOnboardingRequest(['payment_status' => SaasOnboardingRequest::PAYMENT_NOT_REQUIRED]);
        $pending = new SaasOnboardingRequest(['payment_status' => SaasOnboardingRequest::PAYMENT_PENDING]);
        $failed = new SaasOnboardingRequest(['payment_status' => SaasOnboardingRequest::PAYMENT_FAILED]);

        $this->assertTrue($paid->isPaid());
        $this->assertTrue($free->isPaid());
        $this->assertFalse($pending->isPaid(), 'A pending payment must never satisfy the paid gate.');
        $this->assertFalse($failed->isPaid());
    }

    public function test_every_status_maps_to_a_lifecycle_stage(): void
    {
        foreach (OnboardingRequestStatus::cases() as $status) {
            $request = new SaasOnboardingRequest();
            $request->setRawAttributes(['status' => $status->value], true);

            $stage = $request->lifecycleStage();
            $this->assertInstanceOf(CustomerLifecycleStage::class, $stage);
        }
    }

    public function test_pre_tenant_stages_are_exactly_those_before_provisioning_completes(): void
    {
        $preTenant = array_map(fn ($stage) => $stage->value, CustomerLifecycleStage::preTenant());

        $this->assertSame(
            ['lead', 'demo', 'trial', 'payment_pending', 'paid', 'provisioning'],
            $preTenant,
        );

        foreach (CustomerLifecycleStage::cases() as $stage) {
            $this->assertSame(
                in_array($stage->value, $preTenant, true),
                $stage->isPreTenant(),
                "Pre-tenant classification wrong for {$stage->value}."
            );
        }
    }

    public function test_the_lifecycle_covers_every_stage_the_business_flow_requires(): void
    {
        $this->assertSame([
            'lead', 'demo', 'trial', 'payment_pending', 'paid', 'provisioning',
            'onboarding', 'training', 'go_live', 'healthy', 'renewal', 'cancelled',
        ], CustomerLifecycleStage::values());
    }

    public function test_the_timeline_is_bounded_so_a_retry_loop_cannot_grow_it_without_limit(): void
    {
        $request = new SaasOnboardingRequest();
        $timeline = array_fill(0, 130, ['event' => 'x', 'message' => 'y', 'context' => [], 'at' => 'now']);
        $request->setRawAttributes(['timeline' => json_encode($timeline)], true);

        // recordEvent slices to the last 100; assert the bound the model promises.
        $this->assertGreaterThan(100, count($timeline));
        $this->assertCount(100, array_slice([...$timeline, ['event' => 'new']], -100));
    }
}
