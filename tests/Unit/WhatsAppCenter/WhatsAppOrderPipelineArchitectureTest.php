<?php

namespace Tests\Unit\WhatsAppCenter;

use PHPUnit\Framework\TestCase;

class WhatsAppOrderPipelineArchitectureTest extends TestCase
{
    public function test_whatsapp_checkout_uses_shared_order_pipeline_and_customer_resolution(): void
    {
        $engine = file_get_contents(__DIR__.'/../../../Modules/WhatsAppCenter/app/Services/WhatsAppOrderingEngine.php');

        $this->assertStringContainsString('CreateOrderServiceInterface $orders', $engine);
        $this->assertStringContainsString('CustomerServiceInterface $customers', $engine);
        $this->assertStringContainsString('$this->orders->createForActor(', $engine);
        $this->assertStringContainsString('trustedServerCharges: true', $engine);
        $this->assertStringContainsString("->where('tenant_id', \$conversation->tenant_id)", $engine);
        $this->assertStringNotContainsString("'notes' => 'WHATSAPP ORDER: '", $engine);
        $this->assertStringNotContainsString('event(new OrderCreated(', $engine);
    }

    public function test_shared_order_service_blocks_delivery_without_tenant_entitlement(): void
    {
        $service = file_get_contents(__DIR__.'/../../../Modules/Order/app/Services/OrderCreate/CreateOrderService.php');

        $this->assertStringContainsString('EffectiveTenantEntitlementService $entitlements', $service);
        $this->assertStringContainsString("\$this->entitlements->has(\$tenant, 'delivery')", $service);
        $this->assertStringContainsString('Delivery is not included in this restaurant subscription.', $service);
        $this->assertStringContainsString('Delivery is not enabled for this restaurant branch.', $service);
        $this->assertStringContainsString("data_get(\$data, 'fulfilment.source') === 'whatsapp'", $service);
        $this->assertStringContainsString("\$additionalPayments->keys()->all() === ['customer_delivery_fee']", $service);

        $engine = file_get_contents(__DIR__.'/../../../Modules/WhatsAppCenter/app/Services/WhatsAppOrderingEngine.php');
        $routes = file_get_contents(__DIR__.'/../../../Modules/Order/routes/api/v1.php');
        $this->assertStringContainsString('capabilitiesForTenant', $engine);
        $this->assertStringContainsString('tenantHasDelivery', $engine);
        $this->assertGreaterThanOrEqual(2, substr_count($routes, "'tenant.feature:delivery'"));
    }

    public function test_shared_order_create_service_is_registered_for_queue_workers(): void
    {
        $provider = file_get_contents(__DIR__.'/../../../Modules/Order/app/Providers/DeferredOrderServiceProvider.php');

        $this->assertStringContainsString('CreateOrderServiceInterface::class', $provider);
        $this->assertStringContainsString('fn ($app) => $app->make(CreateOrderService::class)', $provider);
    }

    public function test_catalog_mapping_admin_uses_public_product_ids_and_tenant_branch_context(): void
    {
        $controller = file_get_contents(__DIR__.'/../../../Modules/WhatsAppCenter/app/Http/Controllers/Api/V1/WhatsAppOrderingController.php');
        $routes = file_get_contents(__DIR__.'/../../../Modules/WhatsAppCenter/routes/api/v1.php');

        $this->assertStringContainsString("Route::get('/catalog/mappings'", $routes);
        $this->assertStringContainsString("Route::put('/catalog/mappings/{productUuid}'", $routes);
        $this->assertStringContainsString("->whereUuid('productUuid')", $routes);
        $this->assertStringContainsString("'branch_uuid' => ['required', 'uuid']", $controller);
        $this->assertStringContainsString('$this->assertAssignmentPermitsBranch($assignment, $branch)', $controller);
        $this->assertStringContainsString("'product_uuid' => \$product->uuid", $controller);
        $this->assertStringNotContainsString("'product_id' => \$product->id,", substr($controller, strpos($controller, 'public function catalogMappings'), strpos($controller, 'public function saveCatalogMapping') - strpos($controller, 'public function catalogMappings')));
    }

    public function test_restaurant_connection_resolves_the_provider_profile_model(): void
    {
        $controller = file_get_contents(__DIR__.'/../../../Modules/WhatsAppCenter/app/Http/Controllers/Api/V1/WhatsAppOrderingController.php');

        $this->assertStringContainsString('use Modules\\WhatsAppCenter\\Models\\WhatsAppProviderProfile;', $controller);
        $this->assertStringContainsString('WhatsAppProviderProfile::query()->create(', $controller);
    }

    public function test_native_catalog_orders_respect_manual_approval_and_notify_the_customer(): void
    {
        $engine = file_get_contents(__DIR__.'/../../../Modules/WhatsAppCenter/app/Services/WhatsAppOrderingEngine.php');
        $job = file_get_contents(__DIR__.'/../../../Modules/WhatsAppCenter/app/Jobs/ProcessWhatsAppOrderingMessage.php');
        $listener = file_get_contents(__DIR__.'/../../../Modules/Order/app/Listeners/SendOrderWhatsAppNotification.php');

        $this->assertStringContainsString("data_get(\$context->capabilities, 'approval_mode', 'manual')", $engine);
        $this->assertStringContainsString("'state' => 'waiting_approval'", $engine);
        $this->assertStringContainsString('Kitchen: *Not sent yet*', $job);
        $this->assertStringContainsString('After payment is verified, your order will be sent to the kitchen automatically', $job);
        $this->assertStringContainsString('addMinutes(30)', $job);
        $this->assertStringContainsString("'event' => 'catalog_order_received'", $job);
        $this->assertStringContainsString('$existingAcknowledgement', $job);
        $this->assertStringContainsString("\$this->markEvent('failed', \$this->safeFailureReason(\$exception))", $job);
        $this->assertStringContainsString("'event' => 'catalog_order_rejected'", $job);
        $this->assertStringContainsString('Order Could Not Be Accepted', $job);
        $this->assertStringContainsString("'state' => 'browsing'", $engine);
        $this->assertStringContainsString('catalogOrderFailureMessage', $job);
        $this->assertStringContainsString("'KITCHEN_RELEASED_AFTER_APPROVAL'", $engine);
        $this->assertStringContainsString('has been sent to the kitchen', $listener);
    }

    public function test_delivery_failures_send_admin_phone_alerts(): void
    {
        $overdue = file_get_contents(__DIR__.'/../../../Modules/Order/app/Jobs/EscalateUnassignedDeliveries.php');
        $assignment = file_get_contents(__DIR__.'/../../../Modules/Order/app/Jobs/AssignOrderDelivery.php');
        $alert = file_get_contents(__DIR__.'/../../../Modules/Order/app/Jobs/SendStaffDeliveryActionAlert.php');

        $this->assertStringContainsString('SendStaffDeliveryActionAlert::dispatch', $overdue);
        $this->assertStringContainsString('No rider was assigned within 15 minutes', $overdue);
        $this->assertStringContainsString("'staff_delivery_rider_unassigned'", $overdue);
        $this->assertStringContainsString('SendStaffDeliveryActionAlert::dispatch', $assignment);
        $this->assertStringContainsString('Delivery Wallet Insufficient', $assignment);
        $this->assertStringContainsString("'audience' => 'staff_delivery_action'", $alert);
        $this->assertStringContainsString("setting('order_phone_alert_numbers', [])", $alert);
    }

    public function test_order_acknowledgement_matches_payment_capability_and_preserves_cancel_action(): void
    {
        $job = file_get_contents(__DIR__.'/../../../Modules/WhatsAppCenter/app/Jobs/ProcessWhatsAppOrderingMessage.php');

        $this->assertStringContainsString("\$paymentsEnabled = (bool) data_get(\$context->capabilities, 'payments', false)", $job);
        $this->assertStringContainsString("'payment_required' => ! \$isReleased && filled(\$paymentToken)", $job);
        $this->assertStringContainsString('Awaiting restaurant confirmation', $job);
        $this->assertStringContainsString('Use Cancel Order within the allowed cancellation window', $job);
        $this->assertStringContainsString("'/v1/customer-app/order-payment/'", $job);
        $this->assertStringContainsString('Pay securely (valid for 30 minutes)', $job);
        $this->assertStringContainsString('$tenantContext->setId($this->tenantId)', $job);
        $this->assertStringContainsString('$settings->refreshSettingBinding()', $job);
    }

    public function test_whatsapp_cancel_button_requires_confirmation_and_rechecks_refund_eligibility(): void
    {
        $job = file_get_contents(__DIR__.'/../../../Modules/WhatsAppCenter/app/Jobs/ProcessWhatsAppOrderingMessage.php');
        $provider = file_get_contents(__DIR__.'/../../../Modules/WhatsAppCenter/app/Services/Providers/NexMsgOrderingProvider.php');

        $this->assertStringContainsString("'sub_type' => 'quick_reply'", $provider);
        $this->assertStringContainsString("'payload' => 'cancel_order:'.\$cancelToken", $provider);
        $this->assertStringContainsString('CONFIRM CANCELLATION', $job);
        $this->assertStringContainsString('cancellableOrderForToken($conversation, $token, true)', $job);
        $this->assertStringContainsString('OrderProductStatus::Pending', $job);
        $this->assertStringContainsString('RefundCustomerCancelledOrder::dispatch', $job);
        $this->assertStringContainsString('WHATSAPP_CUSTOMER_CANCELLED', $job);
    }

    public function test_verified_online_payment_notifies_once_after_kitchen_release(): void
    {
        $payments = file_get_contents(__DIR__.'/../../../Modules/Order/app/Models/Concerns/HasOrderPayments.php');
        $razorpay = file_get_contents(__DIR__.'/../../../Modules/Payment/app/Services/RazorpayCheckoutService.php');
        $upi = file_get_contents(__DIR__.'/../../../Modules/Payment/app/Services/DirectUpiService.php');
        $listener = file_get_contents(__DIR__.'/../../../Modules/Order/app/Listeners/SendOrderWhatsAppNotification.php');

        $this->assertStringContainsString('DB::afterCommit(fn () => event(new OrderPaid($this->fresh())))', $payments);
        $this->assertStringNotContainsString('new OrderPaid(', $razorpay);
        $this->assertStringNotContainsString('X-Razorpay-Account', $razorpay);
        $this->assertStringNotContainsString('new OrderPaid(', $upi);
        $this->assertStringContainsString('DB::afterCommit(function () use ($orderId): void', $razorpay);
        $this->assertStringContainsString('DB::afterCommit(function () use ($orderId): void', $upi);
        $this->assertLessThan(strpos($razorpay, '$order->refreshDueAmount();'), strpos($razorpay, 'RAZORPAY_PAYMENT_VERIFIED'));
        $this->assertLessThan(strpos($upi, '$order->refreshDueAmount();'), strpos($upi, 'UPI_PAYMENT_VERIFIED'));
        $this->assertStringContainsString('Your order has been sent to the kitchen.', $listener);
    }

    public function test_common_greeting_variants_trigger_the_catalog(): void
    {
        foreach (['hi', 'hii', 'hiii', 'hey', 'hello'] as $greeting) {
            $this->assertTrue(\Modules\WhatsAppCenter\Support\WhatsAppOrderingPolicy::isGreeting($greeting));
        }
        $this->assertTrue(\Modules\WhatsAppCenter\Support\WhatsAppOrderingPolicy::isGreeting('custom word', ['greeting_keywords' => ['custom word']]));
        $this->assertFalse(\Modules\WhatsAppCenter\Support\WhatsAppOrderingPolicy::isGreeting('hi', ['greeting_keywords' => ['menu']]));
    }

    public function test_greeting_prompts_for_fulfilment_before_catalog_checkout(): void
    {
        $policy = \Modules\WhatsAppCenter\Support\WhatsAppOrderingPolicy::orderTypePrompt('Snack Sprint', [
            'enabled_order_types' => ['delivery', 'takeaway'],
        ]);
        $engine = file_get_contents(__DIR__.'/../../../Modules/WhatsAppCenter/app/Services/WhatsAppOrderingEngine.php');
        $job = file_get_contents(__DIR__.'/../../../Modules/WhatsAppCenter/app/Jobs/ProcessWhatsAppOrderingMessage.php');

        $this->assertStringContainsString('DELIVERY', $policy);
        $this->assertStringContainsString('PICKUP', $policy);
        $this->assertStringContainsString("'state' => 'awaiting_order_type'", $engine);
        $this->assertStringContainsString('Share and confirm your delivery location before submitting', $engine);
        $this->assertStringContainsString('$catalogReady', $job);
        $this->assertStringContainsString('$sendChoices', $job);
        $this->assertStringContainsString('sendOrderTypeChoices(', $job);
    }

    public function test_delivery_address_collects_customer_name_before_house_and_supports_skip_buttons(): void
    {
        $engine = file_get_contents(__DIR__.'/../../../Modules/WhatsAppCenter/app/Services/WhatsAppOrderingEngine.php');

        $nameStep = strpos($engine, 'waiting_delivery_customer_name');
        $houseStep = strpos($engine, '$'."next = 'waiting_delivery_house'");
        $this->assertNotFalse($nameStep);
        $this->assertNotFalse($houseStep);
        $this->assertLessThan($houseStep, $nameStep);
        $this->assertStringContainsString("'recipient_name'", $engine);
        $this->assertStringContainsString('Enter the customer name for this delivery.', $engine);
        $this->assertStringContainsString('Share your house/flat number and street address', $engine);
        $this->assertStringContainsString('Enter your floor or SKIP.', $engine);
        $this->assertStringContainsString('Enter a nearby landmark or SKIP.', $engine);
        $this->assertStringContainsString('📍 *Please Share Your Location*', $engine);
        $job = file_get_contents(__DIR__.'/../../../Modules/WhatsAppCenter/app/Jobs/ProcessWhatsAppOrderingMessage.php');
        $this->assertStringContainsString('$sendSkipAction', $job);
        $this->assertStringContainsString("['skip']", $job);
        $this->assertStringContainsString('CHANGE LOCATION', $engine);
        $this->assertStringContainsString('deliveryIdentity(', $engine);
        $this->assertStringContainsString('deliveryConfirmationMessage(', $engine);
        $this->assertStringContainsString("\$sessionAfter?->state === 'waiting_delivery_location'", $job);
        $this->assertStringContainsString('$deliveryRejected', $job);
        $this->assertStringContainsString('Choose Pickup, or choose Delivery', $engine);
    }

    public function test_payment_readiness_accepts_tested_direct_upi_or_razorpay(): void
    {
        $controller = file_get_contents(__DIR__.'/../../../Modules/WhatsAppCenter/app/Http/Controllers/Api/V1/WhatsAppOrderingController.php');

        $this->assertStringContainsString("->whereIn('provider', ['direct_upi', 'razorpay'])", $controller);
        $this->assertStringContainsString("->where('last_test_status', 'configured')", $controller);
        $this->assertStringContainsString('paymentGatewayReadiness($tenantId)', $controller);
    }

    public function test_order_notifications_require_provider_approval_and_non_empty_variables(): void
    {
        $listener = file_get_contents(__DIR__.'/../../../Modules/Order/app/Listeners/SendOrderWhatsAppNotification.php');

        $this->assertStringContainsString("'20 minutes'", $listener);
        $this->assertStringContainsString('$requiresProviderApproval', $listener);
        $this->assertStringContainsString("'approval_status'", $listener);
        $this->assertStringContainsString('if ($requiresProviderApproval && ! $configured)', $listener);
    }

    public function test_delivery_confirmation_explains_distance_slab_and_currency(): void
    {
        $engine = file_get_contents(__DIR__.'/../../../Modules/WhatsAppCenter/app/Services/WhatsAppOrderingEngine.php');

        $this->assertStringContainsString('%.2f km from the restaurant', $engine);
        $this->assertStringContainsString('km slab, including configured tax', $engine);
        $this->assertStringContainsString("\$branch->currency ?: 'INR'", $engine);
    }
}
