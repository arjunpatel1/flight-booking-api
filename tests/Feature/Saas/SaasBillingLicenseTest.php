<?php

namespace Tests\Feature\Saas;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Modules\Notification\Services\NotificationDispatcherService;
use Modules\Saas\Models\SaasBillingInvoice;
use Modules\Saas\Models\SubscriptionPlan;
use Modules\Saas\Models\Tenant;
use Modules\Saas\Models\TenantSubscription;
use Modules\Saas\Services\Billing\SaasBillingService;
use Modules\Saas\Services\Billing\SaasBillingWebhookService;
use Modules\Saas\Services\Billing\SaasLicenseLifecycleService;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Tests\TestCase;

#[RequiresPhpExtension('pdo_sqlite')]
class SaasBillingLicenseTest extends TestCase
{
    use RefreshDatabase;

    public function test_coupon_application_discounts_invoice_and_enforces_per_tenant_limit(): void
    {
        [$tenant, $subscription] = $this->tenantSubscription();
        $service = app(SaasLicenseLifecycleService::class);
        $coupon = $service->createCoupon([
            'code' => 'pilot50',
            'type' => 'percentage',
            'value' => 50,
            'per_tenant_limit' => 1,
        ]);

        $result = $service->applyCoupon($subscription, 'pilot50');

        $this->assertSame('PILOT50', $coupon->refresh()->code);
        $this->assertSame('500.00', $result['invoice']->amount);
        $this->assertDatabaseHas('saas_coupon_redemptions', [
            'saas_coupon_id' => $coupon->id,
            'tenant_id' => $tenant->id,
            'discount_amount' => 500,
        ]);

        $this->expectExceptionMessage('Coupon tenant usage limit reached.');
        $service->applyCoupon($subscription->refresh(), 'pilot50');
    }

    public function test_activation_key_activates_tenant_and_updates_subscription_plan(): void
    {
        [$tenant, $subscription] = $this->tenantSubscription(['is_active' => false], ['status' => 'trial']);
        $newPlan = SubscriptionPlan::query()->create([
            'name' => 'Enterprise',
            'code' => 'enterprise',
            'billing_cycle' => 'monthly',
            'price' => 2500,
            'currency' => 'INR',
            'features' => ['pos', 'printer'],
            'is_active' => true,
        ]);

        $service = app(SaasLicenseLifecycleService::class);
        $activation = $service->createActivationKey([
            'key' => 'nxd-custom-key',
            'subscription_plan_id' => $newPlan->id,
            'tenant_id' => $tenant->id,
        ]);

        $service->activateKey('nxd-custom-key', $tenant, ['device_id' => 'tablet-1']);

        $this->assertSame('NXD-CUSTOM-KEY', $activation->refresh()->key);
        $this->assertSame(1, $activation->used_count);
        $this->assertTrue($tenant->refresh()->is_active);
        $this->assertSame($newPlan->id, $subscription->refresh()->subscription_plan_id);
    }

    public function test_razorpay_webhook_marks_invoice_paid_once_with_valid_signature(): void
    {
        $this->withoutBillingNotifications();
        [, $subscription] = $this->tenantSubscription();
        $invoice = app(SaasBillingService::class)->createInvoice($subscription);
        config(['saas.billing.razorpay.webhook_secret' => 'secret']);

        $body = json_encode([
            'event' => 'payment_link.paid',
            'payload' => [
                'payment_link' => [
                    'entity' => [
                        'id' => 'plink_123',
                        'reference_id' => $invoice->invoice_number,
                    ],
                ],
            ],
        ]);

        $request = Request::create('/api/v1/saas/billing/webhooks/razorpay', 'POST', [], [], [], [], $body);
        $request->headers->set('X-Razorpay-Signature', hash_hmac('sha256', $body, 'secret'));

        $result = app(SaasBillingWebhookService::class)->handleRazorpay($request);
        app(SaasBillingWebhookService::class)->handleRazorpay($request);

        $this->assertSame('processed', $result['status']);
        $this->assertSame('paid', $invoice->refresh()->status);
        $this->assertSame(1, $invoice->events()->where('type', 'payment_completed')->count());
    }

    public function test_paid_invoice_supports_audited_partial_and_full_refunds(): void
    {
        $this->withoutBillingNotifications();
        [, $subscription] = $this->tenantSubscription();
        $service = app(SaasBillingService::class);
        $invoice = $service->createInvoice($subscription);
        $invoice->update(['status' => 'paid', 'paid_at' => now()]);

        $first = $service->refund($invoice, 250, 'Duplicate charge', null);
        $this->assertSame('partially_refunded', $invoice->refresh()->status);
        $this->assertSame('250.00', $first->amount);

        $service->refund($invoice->refresh(), 750, 'Customer cancellation', null);
        $this->assertSame('refunded', $invoice->refresh()->status);
        $this->assertDatabaseCount('saas_billing_refunds', 2);
        $this->assertSame(1000.0, (float) $service->taxReport(now()->subDay()->toDateString(), now()->addDay()->toDateString())['summary']['refunded']);
    }

    private function tenantSubscription(array $tenantOverrides = [], array $subscriptionOverrides = []): array
    {
        $tenant = Tenant::query()->create([
            'name' => 'Billing Tenant',
            'slug' => 'billing-tenant',
            'domain' => 'billing.nexdine.test',
            'contact_email' => 'owner@example.com',
            'contact_phone' => '+919000000001',
            'is_active' => true,
            ...$tenantOverrides,
        ]);
        $plan = SubscriptionPlan::query()->create([
            'name' => 'Professional',
            'code' => 'professional',
            'billing_cycle' => 'monthly',
            'price' => 1000,
            'currency' => 'INR',
            'features' => ['pos', 'printer'],
            'is_active' => true,
        ]);
        $subscription = TenantSubscription::query()->create([
            'tenant_id' => $tenant->id,
            'subscription_plan_id' => $plan->id,
            'status' => 'active',
            ...$subscriptionOverrides,
        ]);

        return [$tenant, $subscription];
    }

    private function withoutBillingNotifications(): void
    {
        $this->app->instance(NotificationDispatcherService::class, new class extends NotificationDispatcherService {
            public function dispatch(string $type, string $recipient, array $payload, array $channels = []): array
            {
                return [];
            }
        });
    }
}
