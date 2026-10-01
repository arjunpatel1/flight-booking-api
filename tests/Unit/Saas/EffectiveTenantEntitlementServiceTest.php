<?php

namespace Tests\Unit\Saas;

use Modules\Saas\Services\Entitlements\EffectiveTenantEntitlementService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EffectiveTenantEntitlementServiceTest extends TestCase
{
    #[Test]
    public function restaurant_specific_feature_overrides_extend_and_reduce_the_package(): void
    {
        $features = app(EffectiveTenantEntitlementService::class)->resolveFeatures(
            ['pos', 'reports'],
            ['_features' => ['whatsapp' => true, 'reports' => false]],
        );

        $this->assertContains('pos', $features);
        $this->assertContains('whatsapp', $features);
        $this->assertNotContains('reports', $features);
    }

    #[Test]
    public function explicit_feature_permissions_override_legacy_values(): void
    {
        $features = app(EffectiveTenantEntitlementService::class)->resolveFeatures(
            ['pos'],
            ['whatsapp' => 1, '_features' => ['whatsapp' => false]],
        );

        $this->assertSame(['pos'], $features);
    }
    #[Test]
    public function starter_trial_keeps_whatsapp_ordering_but_not_paid_delivery(): void
    {
        $features = app(EffectiveTenantEntitlementService::class)->applySubscriptionPolicy(
            ['pos', 'online_ordering', 'whatsapp', 'whatsapp_ordering', 'delivery'], 'trial',
        );
        $this->assertSame(['pos', 'online_ordering', 'whatsapp', 'whatsapp_ordering'], $features);
    }

    #[Test]
    public function ordering_capabilities_enable_the_whatsapp_ordering_workspace(): void
    {
        $features = app(EffectiveTenantEntitlementService::class)->resolveFeatures(
            ['pos', 'whatsapp_managed_api', 'whatsapp_order_payments'], [],
        );

        $this->assertContains('whatsapp_ordering', $features);
    }

    #[Test]
    public function active_subscription_retains_agreed_package_services(): void
    {
        $features = ['pos', 'whatsapp_ordering', 'delivery'];
        $this->assertSame($features, app(EffectiveTenantEntitlementService::class)->applySubscriptionPolicy($features, 'active'));
    }
}
