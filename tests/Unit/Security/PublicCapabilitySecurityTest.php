<?php

namespace Tests\Unit\Security;

use Modules\Order\Http\Requests\Api\V1\PublicQrOrderRequest;
use Modules\Order\Http\Requests\Api\V1\StoreOrderFeedbackRequest;
use Modules\Order\Models\Order;
use Modules\Pos\Http\Requests\Api\V1\CallWaiterRequest;
use Modules\Pos\Services\QRCode\QRCodeService;
use Modules\Pos\Services\QRCode\QRCodeServiceInterface;
use SimpleSoftwareIO\QrCode\Generator;
use Tests\TestCase;

class PublicCapabilitySecurityTest extends TestCase
{
    public function test_qr_url_service_honours_its_string_contract(): void
    {
        $url = (new QRCodeService())->getQRCodeUrl(1, 2);

        $this->assertIsString($url);
        $this->assertStringEndsWith('/qr/table/2/1', $url);
    }

    public function test_printed_table_qrs_open_a_public_menu_with_only_a_table_capability(): void
    {
        $source = file_get_contents(base_path('Modules/Pos/app/Services/QRCode/QRCodeService.php'));

        $this->assertStringContainsString("'online-menu'", $source);
        $this->assertStringContainsString("'?table_token='", $source);
        $this->assertStringContainsString('(string) $table->uuid', $source);
        $this->assertStringNotContainsString("'/online-menu/'.\$table->id", $source);
    }

    public function test_qr_generator_uses_the_installed_library_and_safe_format_fallback(): void
    {
        $this->assertTrue(class_exists(Generator::class));

        $source = file_get_contents(base_path('Modules/Pos/app/Services/QRCode/QRCodeService.php'));
        $this->assertIsString($source);
        $this->assertStringContainsString("extension_loaded('imagick') ? 'png' : 'svg'", $source);
        $this->assertStringNotContainsString('SimpleSoftwareIO\\SimpleQrcode', $source);
    }

    public function test_qr_service_contract_is_registered_in_the_container(): void
    {
        $this->assertInstanceOf(QRCodeService::class, app(QRCodeServiceInterface::class));
    }

    public function test_public_table_actions_prohibit_numeric_object_ids(): void
    {
        $orderRules = (new PublicQrOrderRequest())->rules();
        $waiterRules = (new CallWaiterRequest())->rules();

        $this->assertContains('prohibited', $orderRules['table_id']);
        $this->assertContains('uuid', $orderRules['table_token']);
        $this->assertArrayHasKey('table_qr_payload', $orderRules);
        $this->assertArrayHasKey('table_number', $orderRules);
        $this->assertNotContains('prohibited', $orderRules['table_number']);
        $this->assertContains('prohibited', $waiterRules['table_id']);
        $this->assertContains('uuid', $waiterRules['table_token']);
        $this->assertArrayHasKey('table_number', $waiterRules);
    }

    public function test_public_feedback_prohibits_numeric_order_ids(): void
    {
        $rules = (new StoreOrderFeedbackRequest())->rules();

        $this->assertContains('prohibited', $rules['order_id']);
        $this->assertContains('required', $rules['order_reference']);
    }

    public function test_new_public_order_references_use_high_entropy_capabilities(): void
    {
        $first = Order::generateReferenceNo();
        $second = Order::generateReferenceNo();

        $this->assertMatchesRegularExpression('/^ORD-[A-Z0-9]{20}$/', $first);
        $this->assertNotSame($first, $second);
    }

    public function test_tracking_never_accepts_predictable_order_numbers(): void
    {
        $source = file_get_contents(base_path(
            'Modules/Order/app/Http/Controllers/Api/V1/OrderController.php'
        ));
        preg_match('/public function publicTracking.*?public function customerAppOrders/s', (string) $source, $match);

        $this->assertNotEmpty($match[0] ?? null);
        $this->assertStringContainsString("->where('reference_no', \$reference)", $match[0]);
        $this->assertStringNotContainsString('order_number', $match[0]);
    }

    public function test_qr_signatures_never_fall_back_to_a_guessable_branch_id_secret(): void
    {
        $source = file_get_contents(base_path('Modules/Pos/app/Services/QRCode/QRCodeService.php'));

        $this->assertStringContainsString("config('app.key')", $source);
        $this->assertStringContainsString('nexdine:table-qr:branch:', $source);
        $this->assertStringContainsString("hash_hmac('sha256'", $source);
    }

    public function test_public_waiter_requests_resolve_customer_tenant_and_target_only_scoped_staff(): void
    {
        $routes = file_get_contents(base_path('Modules/Pos/routes/api/v1.php'));
        $controller = file_get_contents(base_path('Modules/Pos/app/Http/Controllers/Api/V1/QRCodeController.php'));

        $this->assertStringContainsString("ResolveCustomerAppContext::class, 'tenant.feature:qr_ordering'", $routes);
        $this->assertStringContainsString('->where(\'tenant_id\', $tenantId)', $controller);
        $this->assertStringContainsString('abort_if($recipients->isEmpty(), 409', $controller);
        $this->assertStringNotContainsString('], null);', $controller);
    }

    public function test_customer_tracking_payload_does_not_publish_internal_object_ids(): void
    {
        $source = file_get_contents(base_path(
            'Modules/Order/app/Transformers/Api/V1/PublicOrderTrackingResource.php'
        ));

        $this->assertStringNotContainsString("'id' => \$this->table_id", $source);
        $this->assertStringNotContainsString("'id' => \$this->branch_id", $source);
        $this->assertStringNotContainsString("'id' => \$item->id", $source);
        $this->assertStringNotContainsString("'product_id' => \$item->product_id", $source);
        $this->assertStringContainsString("'product_reference' => \$item->product?->uuid", $source);
        $this->assertStringContainsString("'menu_slug'", $source);
    }

    public function test_customer_table_resolution_returns_capabilities_not_database_ids(): void
    {
        $source = file_get_contents(base_path(
            'Modules/Pos/app/Http/Controllers/Api/V1/QRCodeController.php'
        ));
        preg_match('/public function resolve\(.*?public function callWaiter/s', (string) $source, $match);

        $this->assertNotEmpty($match[0] ?? null);
        $this->assertStringContainsString("'table_token' => \$table->uuid", $match[0]);
        $this->assertStringContainsString("'menu_references'", $match[0]);
        $this->assertStringNotContainsString("'table_id' => \$table->id", $match[0]);
        $this->assertStringNotContainsString("'branch_id' => \$table->branch_id", $match[0]);
    }

    public function test_customer_reservation_payload_does_not_publish_branch_ids(): void
    {
        $source = file_get_contents(base_path(
            'Modules/SeatingPlan/app/Http/Controllers/Api/V1/PublicReservationController.php'
        ));
        preg_match('/private function payload\(.*?\n    }\n}/s', (string) $source, $match);

        $this->assertNotEmpty($match[0] ?? null);
        $this->assertStringContainsString("'branch_name'", $match[0]);
        $this->assertStringNotContainsString("'branch_id'", $match[0]);
    }

    public function test_customer_checkout_supports_tenant_scoped_menu_references(): void
    {
        $guard = file_get_contents(base_path('Modules/Cart/app/Support/PublicTenantGuard.php'));
        $cart = file_get_contents(base_path(
            'Modules/Cart/app/Http/Controllers/Api/V1/PublicCartController.php'
        ));
        $items = file_get_contents(base_path(
            'Modules/Cart/app/Http/Controllers/Api/V1/PublicCartItemController.php'
        ));
        $order = file_get_contents(base_path(
            'Modules/Order/app/Http/Requests/Api/V1/PublicQrOrderRequest.php'
        ));

        $this->assertStringContainsString("->where('uuid', \$reference)", $guard);
        $this->assertStringContainsString("->where('tenant_id', self::tenantId(\$request))", $guard);
        $this->assertStringContainsString("'menu_reference'", $cart);
        $this->assertStringContainsString("'menu_reference'", $items);
        $this->assertStringContainsString('prepareForValidation', $order);
        $this->assertStringContainsString("'menu_reference'", $order);
    }

    public function test_customer_payment_options_use_scoped_opaque_references_with_legacy_fallback(): void
    {
        $controller = file_get_contents(base_path(
            'Modules/Payment/app/Http/Controllers/Api/V1/RazorpayCheckoutController.php'
        ));
        $routes = file_get_contents(base_path('Modules/Payment/routes/api/v1.php'));

        $this->assertStringContainsString("'menu_reference'", $controller);
        $this->assertStringContainsString("'order_reference'", $controller);
        $this->assertStringContainsString("Auth::guard('sanctum')->user()", $controller);
        $this->assertStringContainsString("->where('customer_id', (int) \$customer->id)", $controller);
        $this->assertStringContainsString("->where('tenant_id', \$tenantId)", $controller);
        $this->assertStringContainsString("'branch_id'", $controller);
        $this->assertStringContainsString('PublicTenantGuard::menu', $controller);
        $this->assertStringContainsString('PublicTenantGuard::branch', $controller);
        preg_match("/Route::get\('customer-app\/payments\/options'.*?\);/s", (string) $routes, $route);
        $this->assertNotEmpty($route[0] ?? null);
        $this->assertStringNotContainsString('ResolveCustomerAppContext', $route[0]);
        // Basic checkout options (for example pay-at-counter) must remain
        // discoverable without the paid payments entitlement. Individual
        // online gateways are filtered by the controller's tenant checks.
        $this->assertStringNotContainsString("'tenant.feature:payments'", $route[0]);
    }

    public function test_customer_sessions_publish_tenant_scoped_opaque_identity_references(): void
    {
        $controller = file_get_contents(base_path(
            'Modules/User/app/Http/Controllers/Api/V1/CustomerAuthController.php'
        ));
        $user = file_get_contents(base_path('Modules/User/app/Models/User.php'));
        $branch = file_get_contents(base_path('Modules/Branch/app/Models/Branch.php'));

        $this->assertStringContainsString("'reference' => \$user->uuid", $controller);
        $this->assertStringContainsString("'tenant_reference'", $controller);
        $this->assertStringContainsString("'branch_reference'", $controller);
        $this->assertStringContainsString("->where('tenant_id', \$user->tenant_id)", $controller);
        $this->assertStringContainsString('HasUuid', $user);
        $this->assertStringContainsString('HasUuid', $branch);
    }

    public function test_customer_product_favourites_support_tenant_scoped_opaque_references(): void
    {
        $controller = file_get_contents(base_path(
            'Modules/User/app/Http/Controllers/Api/V1/CustomerEngagementController.php'
        ));

        $this->assertStringContainsString("'product_references'", $controller);
        $this->assertStringContainsString("'subject_reference'", $controller);
        $this->assertStringContainsString("->where('uuid', \$reference)", $controller);
        $this->assertStringContainsString("(int) \$product->branch?->tenant_id === \$tenantId", $controller);
        $this->assertStringContainsString("'product_ids'", $controller);
    }

    public function test_customer_notification_feed_does_not_publish_owner_database_ids(): void
    {
        $controller = file_get_contents(base_path(
            'Modules/User/app/Http/Controllers/Api/V1/CustomerExperienceController.php'
        ));
        preg_match('/public function notifications\(.*?\n    }/s', $controller, $match);

        $this->assertNotEmpty($match[0] ?? null);
        $this->assertStringContainsString("'id' => \$item->reference", $match[0]);
        $this->assertStringNotContainsString("'tenant_id' =>", $match[0]);
        $this->assertStringNotContainsString("'customer_id' =>", $match[0]);
    }

    public function test_customer_checkout_uses_opaque_menu_scoped_product_references(): void
    {
        $model = file_get_contents(base_path('Modules/Product/app/Models/Product.php'));
        $resource = file_get_contents(base_path(
            'Modules/Pos/app/Transformers/Api/V1/Pos/PosProductResource.php'
        ));
        $items = file_get_contents(base_path(
            'Modules/Cart/app/Http/Controllers/Api/V1/PublicCartItemController.php'
        ));

        $this->assertStringContainsString('HasUuid', $model);
        $this->assertStringContainsString('"reference" => $attributes[\'uuid\'] ?? null', $resource);
        $this->assertStringContainsString("'items.*.product_reference'", $items);
        $this->assertStringContainsString("->whereIn('uuid', \$productReferences)", $items);
        $this->assertStringContainsString("->where('branch_id', \$branch->id)", $items);
        $singleItemRequest = file_get_contents(base_path(
            'Modules/Cart/app/Http/Requests/Api/V1/Public/StoreCartItemRequest.php'
        ));
        $this->assertStringContainsString("'product_reference'", $singleItemRequest);
        $this->assertStringContainsString('PublicTenantGuard::menu', $singleItemRequest);
        $this->assertStringContainsString("->where('uuid', \$this->input('product_reference'))", $singleItemRequest);
    }

    public function test_group_order_creation_and_items_use_opaque_catalog_references(): void
    {
        $controller = file_get_contents(base_path(
            'Modules/Cart/app/Http/Controllers/Api/V1/CustomerGroupOrderController.php'
        ));
        $service = file_get_contents(base_path(
            'Modules/Cart/app/Services/CustomerGroupOrderService.php'
        ));

        $this->assertStringContainsString("'menu_reference' => ['required_without:branch_id'", $controller);
        $this->assertStringContainsString("'product_reference' => ['required_without:product_id'", $controller);
        $this->assertStringContainsString('PublicTenantGuard::menu', $service);
        $this->assertStringContainsString("->where('uuid', \$reference)", $service);
        $this->assertStringContainsString("->where('branch_id', \$group->branch_id)", $service);
    }

    public function test_group_order_payload_and_participant_actions_do_not_expose_numeric_ids(): void
    {
        $routes = file_get_contents(base_path('Modules/Cart/routes/api/v1.php'));
        $service = file_get_contents(base_path('Modules/Cart/app/Services/CustomerGroupOrderService.php'));
        $model = file_get_contents(base_path('Modules/Cart/app/Models/CustomerGroupParticipant.php'));

        $this->assertStringContainsString("whereUuid('participantReference')", $routes);
        $this->assertStringContainsString("->where('uuid', \$participantReference)", $service);
        $this->assertStringContainsString("'reference' => \$member->uuid", $service);
        $this->assertStringContainsString("'participant_reference' => \$item->participant?->uuid", $service);
        $this->assertStringContainsString("'product_reference' => \$item->product?->uuid", $service);
        $this->assertStringNotContainsString("'restaurant' => ['branch_id'", $service);
        $this->assertStringContainsString('use HasUuid;', $model);
    }

    public function test_public_product_customisations_support_opaque_references(): void
    {
        $trait = file_get_contents(base_path('Modules/Cart/app/Traits/ValidatesCartItemOptions.php'));
        $option = file_get_contents(base_path('Modules/Pos/app/Transformers/Api/V1/Pos/PosProductOptionResource.php'));
        $value = file_get_contents(base_path('Modules/Pos/app/Transformers/Api/V1/Pos/PosProductOptionValueResource.php'));

        $this->assertStringContainsString('normalizeCartItemOptionReferences', $trait);
        $this->assertStringContainsString("firstWhere('uuid'", $trait);
        $this->assertStringContainsString('"reference" => $this->uuid', $option);
        $this->assertStringContainsString("'reference' => \$this->uuid", $value);
    }
}
