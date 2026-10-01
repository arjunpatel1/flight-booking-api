<?php

namespace Modules\Saas\Services\Billing;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Saas\Models\SaasActivationKey;
use Modules\Saas\Models\SaasBillingInvoice;
use Modules\Saas\Models\SaasCoupon;
use Modules\Saas\Models\SaasCouponRedemption;
use Modules\Saas\Models\Tenant;
use Modules\Saas\Models\TenantSubscription;
use Modules\Saas\Services\Provisioning\SaasTenantLifecycleService;

class SaasLicenseLifecycleService
{
    public function __construct(
        private readonly SaasBillingService $billing,
        private readonly SaasTenantLifecycleService $tenantLifecycle,
    ) {
    }

    public function createCoupon(array $data): SaasCoupon
    {
        return SaasCoupon::query()->create([
            ...$data,
            'code' => Str::upper(trim($data['code'] ?? Str::random(10))),
            'source' => $data['source'] ?? 'admin',
            'is_active' => $data['is_active'] ?? true,
        ]);
    }

    public function applyCoupon(TenantSubscription $subscription, string $code, array $context = []): array
    {
        return DB::transaction(function () use ($subscription, $code, $context) {
            $subscription->loadMissing('tenant', 'plan');
            $context = [
                'email' => $context['email'] ?? $subscription->tenant?->contact_email,
                'mobile' => $context['mobile'] ?? $subscription->tenant?->contact_phone,
                ...$context,
            ];
            $coupon = SaasCoupon::query()
                ->where('code', Str::upper(trim($code)))
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertCouponUsable($coupon, $subscription, $context);

            $invoice = $this->billing->createInvoice($subscription);
            $discount = $this->discountAmount($coupon, (float) $invoice->amount);

            $invoice->update([
                'amount' => max(0, round((float) $invoice->amount - $discount, 2)),
                'metadata' => [
                    ...($invoice->metadata ?: []),
                    'coupon' => [
                        'id' => $coupon->id,
                        'code' => $coupon->code,
                        'discount_amount' => $discount,
                    ],
                ],
            ]);

            if ($coupon->free_months > 0) {
                $subscription->update(['ends_at' => ($subscription->ends_at ?: now())->addMonths($coupon->free_months)]);
            }

            if ($coupon->trial_extension_days > 0) {
                $subscription->update(['trial_ends_at' => ($subscription->trial_ends_at ?: now())->addDays($coupon->trial_extension_days)]);
            }

            if ($coupon->plan_upgrade_id) {
                $subscription->update(['subscription_plan_id' => $coupon->plan_upgrade_id]);
            }

            if ($coupon->lifetime) {
                $subscription->update([
                    'overrides' => [
                        ...($subscription->overrides ?: []),
                        'billing_lifetime_coupon' => [
                            'id' => $coupon->id,
                            'code' => $coupon->code,
                            'type' => $coupon->type,
                            'value' => $coupon->value,
                            'applied_at' => now()->toISOString(),
                        ],
                    ],
                ]);
            }

            $redemption = SaasCouponRedemption::query()->create([
                'saas_coupon_id' => $coupon->id,
                'tenant_id' => $subscription->tenant_id,
                'tenant_subscription_id' => $subscription->id,
                'saas_billing_invoice_id' => $invoice->id,
                'email' => $context['email'] ?? $subscription->tenant?->contact_email,
                'mobile' => $context['mobile'] ?? $subscription->tenant?->contact_phone,
                'discount_amount' => $discount,
                'metadata' => $context,
                'redeemed_at' => now(),
            ]);

            $coupon->increment('used_count');
            $invoice->events()->create([
                'type' => 'coupon_applied',
                'status' => 'processed',
                'message' => "Coupon {$coupon->code} applied.",
                'payload' => ['redemption_id' => $redemption->id],
                'processed_at' => now(),
            ]);

            return ['coupon' => $coupon->refresh(), 'redemption' => $redemption, 'invoice' => $invoice->refresh()];
        });
    }

    public function createActivationKey(array $data): SaasActivationKey
    {
        return SaasActivationKey::query()->create([
            ...$data,
            'key' => Str::upper(trim($data['key'] ?? $this->generateKey())),
            'status' => $data['status'] ?? 'active',
        ]);
    }

    public function activateKey(string $key, Tenant $tenant, array $context = []): SaasActivationKey
    {
        return DB::transaction(function () use ($key, $tenant, $context) {
            $activation = SaasActivationKey::query()
                ->where('key', Str::upper(trim($key)))
                ->lockForUpdate()
                ->firstOrFail();

            abort_if($activation->status !== 'active', 422, 'Activation key is not active.');
            abort_if($activation->revoked_at, 422, 'Activation key has been revoked.');
            abort_if($activation->expires_at && $activation->expires_at->isPast(), 422, 'Activation key has expired.');
            abort_if($activation->tenant_id && (int) $activation->tenant_id !== (int) $tenant->id, 403, 'Activation key belongs to another tenant.');
            abort_if($activation->used_count >= $activation->activation_limit, 422, 'Activation key usage limit reached.');

            $subscription = $tenant->subscriptions()->latest()->first();
            if ($subscription && $activation->subscription_plan_id) {
                $subscription->update([
                    'subscription_plan_id' => $activation->subscription_plan_id,
                    'status' => 'active',
                    'starts_at' => $subscription->starts_at ?: now(),
                    'ends_at' => $subscription->ends_at ?: now()->addMonth(),
                ]);
            }

            $activation->increment('used_count');
            $activation->events()->create([
                'tenant_id' => $tenant->id,
                'type' => 'activated',
                'status' => 'processed',
                'device_id' => $context['device_id'] ?? null,
                'ip_address' => $context['ip_address'] ?? request()?->ip(),
                'message' => 'Activation key used successfully.',
                'payload' => $context,
                'processed_at' => now(),
            ]);

            $this->tenantLifecycle->activate($tenant);

            return $activation->refresh();
        });
    }

    public function runLifecycle(): array
    {
        $subscriptions = TenantSubscription::query()
            ->withoutGlobalScopes()
            ->with(['tenant', 'plan'])
            ->whereIn('status', ['trial', 'active', 'grace', 'past_due'])
            ->get();

        return [
            'processed' => $subscriptions->map(fn (TenantSubscription $subscription) => $this->processSubscription($subscription))->all(),
            'dunning' => $this->billing->runDunning(),
        ];
    }

    public function processSubscription(TenantSubscription $subscription): array
    {
        $subscription->loadMissing('tenant', 'plan');
        $now = now();
        $graceDays = (int) config('saas.billing.grace_days', 7);
        $result = ['subscription_id' => $subscription->id, 'status' => $subscription->status, 'actions' => []];

        if ($subscription->status === 'trial' && $subscription->trial_ends_at) {
            $days = $now->diffInDays($subscription->trial_ends_at, false);
            if (in_array($days, config('saas.billing.trial_reminder_days', [30, 15, 7, 3, 1]), true)) {
                $this->notice($subscription, "trial_ending_{$days}_days");
                $result['actions'][] = "trial_ending_{$days}_days";
            }
            if ($subscription->trial_ends_at->isPast()) {
                $subscription->update(['status' => 'grace', 'ends_at' => $now->copy()->addDays($graceDays)]);
                $this->notice($subscription->refresh(), 'trial_grace_started');
                $result['actions'][] = 'trial_grace_started';
            }
        }

        if (in_array($subscription->status, ['active', 'grace', 'past_due'], true) && $subscription->ends_at && $subscription->ends_at->isPast()) {
            if ($subscription->status !== 'grace') {
                $subscription->update(['status' => 'grace', 'ends_at' => $now->copy()->addDays($graceDays)]);
                $this->notice($subscription->refresh(), 'subscription_grace_started');
                $result['actions'][] = 'subscription_grace_started';
            } else {
                $subscription->update(['status' => 'expired']);
                if ($subscription->tenant) {
                    $this->tenantLifecycle->suspend($subscription->tenant, 'subscription_expired');
                }
                $this->notice($subscription->refresh(), 'tenant_suspended_billing');
                $result['actions'][] = 'tenant_suspended_billing';
            }
        }

        return $result + ['final_status' => $subscription->refresh()->status];
    }

    private function assertCouponUsable(SaasCoupon $coupon, TenantSubscription $subscription, array $context): void
    {
        abort_unless($coupon->is_active, 422, 'Coupon is inactive.');
        abort_if($coupon->starts_at && $coupon->starts_at->isFuture(), 422, 'Coupon is not active yet.');
        abort_if($coupon->expires_at && $coupon->expires_at->isPast(), 422, 'Coupon has expired.');
        abort_if($coupon->usage_limit !== null && $coupon->used_count >= $coupon->usage_limit, 422, 'Coupon usage limit reached.');

        $query = SaasCouponRedemption::query()->where('saas_coupon_id', $coupon->id);
        abort_if($coupon->per_tenant_limit && (clone $query)->where('tenant_id', $subscription->tenant_id)->count() >= $coupon->per_tenant_limit, 422, 'Coupon tenant usage limit reached.');
        abort_if($coupon->per_email_limit && ! empty($context['email']) && (clone $query)->where('email', $context['email'])->count() >= $coupon->per_email_limit, 422, 'Coupon email usage limit reached.');
        abort_if($coupon->per_mobile_limit && ! empty($context['mobile']) && (clone $query)->where('mobile', $context['mobile'])->count() >= $coupon->per_mobile_limit, 422, 'Coupon mobile usage limit reached.');
    }

    private function discountAmount(SaasCoupon $coupon, float $amount): float
    {
        $discount = match ($coupon->type) {
            'flat' => (float) $coupon->value,
            'free_months', 'trial_extension', 'plan_upgrade' => 0.0,
            default => round($amount * ((float) $coupon->value / 100), 2),
        };

        if ($coupon->maximum_discount !== null) {
            $discount = min($discount, (float) $coupon->maximum_discount);
        }

        return min($amount, max(0, round($discount, 2)));
    }

    private function notice(TenantSubscription $subscription, string $type): ?object
    {
        $invoice = $subscription->invoices()->whereNull('metadata->service_feature')->latest()->first() ?: $this->billing->createInvoice($subscription);

        $alreadySentToday = $invoice->events()
            ->where('type', $type)
            ->whereDate('created_at', now()->toDateString())
            ->exists();

        if ($alreadySentToday) {
            return null;
        }

        return $this->billing->sendBillingAlert($invoice, $type);
    }

    private function generateKey(): string
    {
        return collect(['NXD', Str::upper(Str::random(4)), Str::upper(Str::random(4)), Str::upper(Str::random(4))])->join('-');
    }
}
