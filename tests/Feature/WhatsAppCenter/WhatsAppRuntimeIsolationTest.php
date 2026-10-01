<?php

namespace Tests\Feature\WhatsAppCenter;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Modules\Branch\Models\Branch;
use Modules\Saas\Models\Tenant;
use Modules\WhatsAppCenter\Exceptions\WhatsAppContextException;
use Modules\WhatsAppCenter\Models\WhatsAppPhoneNumber;
use Modules\WhatsAppCenter\Models\WhatsAppProviderProfile;
use Modules\WhatsAppCenter\Models\WhatsAppTenantAssignment;
use Modules\WhatsAppCenter\Services\Providers\Msg91OrderingProvider;
use Modules\WhatsAppCenter\Services\WhatsAppChannelContextResolver;
use Modules\WhatsAppCenter\Transformers\Api\V1\WhatsAppConnectionResource;
use Tests\TestCase;

class WhatsAppRuntimeIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenantA;
    private Tenant $tenantB;
    private Branch $branchA;
    private Branch $branchB;
    private WhatsAppProviderProfile $profile;
    private WhatsAppPhoneNumber $number;
    private WhatsAppTenantAssignment $assignment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantA = Tenant::query()->create(['name' => 'Tenant A', 'slug' => 'wa-a', 'is_active' => true]);
        $this->tenantB = Tenant::query()->create(['name' => 'Tenant B', 'slug' => 'wa-b', 'is_active' => true]);
        $this->branchA = Branch::factory()->create(['tenant_id' => $this->tenantA->id, 'is_active' => true, 'is_accepting_orders' => true]);
        $this->branchB = Branch::factory()->create(['tenant_id' => $this->tenantB->id, 'is_active' => true, 'is_accepting_orders' => true]);
        $this->profile = WhatsAppProviderProfile::query()->withoutGlobalTenant()->create([
            'tenant_id' => $this->tenantA->id, 'name' => 'A WhatsApp', 'ownership_mode' => 'restaurant_owned',
            'provider' => 'msg91', 'credentials' => ['auth_key' => 'secret', 'webhook_secret' => '0123456789abcdef'],
            'status' => 'connected', 'is_active' => true,
        ]);
        $this->number = $this->profile->phoneNumbers()->create([
            'provider_phone_id' => 'phone-a', 'display_number' => '+910000000001', 'status' => 'connected', 'is_active' => true,
        ]);
        $this->assignment = WhatsAppTenantAssignment::query()->withoutGlobalTenant()->create([
            'tenant_id' => $this->tenantA->id, 'provider_profile_id' => $this->profile->id,
            'phone_number_id' => $this->number->id, 'ownership_mode' => 'restaurant_owned',
            'allowed_branch_ids' => [$this->branchA->id], 'capabilities' => ['ordering' => true], 'is_active' => true,
        ]);
    }

    public function test_tenant_a_account_resolves_only_tenant_a_context(): void
    {
        $context = $this->resolve();
        $this->assertSame($this->tenantA->id, $context->tenantId);
        $this->assertNotSame($this->tenantB->id, $context->tenantId);
    }

    public function test_tenant_a_account_cannot_select_tenant_b_product_branch(): void
    {
        $this->assertFalse($this->resolve()->permitsBranch($this->branchB->id));
    }

    public function test_tenant_a_context_cannot_be_reused_for_tenant_b_cart_scope(): void
    {
        $this->assertNotContains($this->branchB->id, $this->resolve()->allowedBranchIds);
    }

    public function test_tenant_a_context_cannot_be_reused_for_tenant_b_order_scope(): void
    {
        $this->assertSame([$this->branchA->id], $this->resolve()->allowedBranchIds);
    }

    public function test_branch_a_account_cannot_use_branch_b(): void
    {
        $this->assertTrue($this->resolve()->permitsBranch($this->branchA->id));
        $this->assertFalse($this->resolve()->permitsBranch($this->branchB->id));
    }

    public function test_disabled_account_fails_safely(): void
    {
        $this->profile->update(['is_active' => false]);
        $this->expectException(WhatsAppContextException::class);
        $this->resolve();
    }

    public function test_disabled_tenant_fails_safely(): void
    {
        $this->tenantA->update(['is_active' => false]);
        $this->expectException(WhatsAppContextException::class);
        $this->resolve();
    }

    public function test_customer_context_is_bound_to_authenticated_assignment(): void
    {
        $this->assertSame($this->assignment->id, $this->resolve()->assignmentId);
    }

    public function test_customer_order_context_uses_an_opaque_assignment_identifier(): void
    {
        $this->assertSame($this->assignment->uuid, $this->resolve()->assignmentUuid);
        $this->assertNotSame((string) $this->assignment->id, $this->resolve()->assignmentUuid);
    }

    public function test_payload_tenant_and_branch_cannot_override_context(): void
    {
        $payload = ['integrated_number' => 'phone-a', 'event_id' => 'event-a', 'from' => '919999999999',
            'text' => 'MENU', 'tenant_id' => $this->tenantB->id, 'branch_id' => $this->branchB->id];
        $request = Request::create('/webhook', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($payload));
        $message = app(Msg91OrderingProvider::class)->normalizeInbound($request);
        $context = app(WhatsAppChannelContextResolver::class)->resolve($this->profile->uuid, $message->providerPhoneId);
        $this->assertSame($this->tenantA->id, $context->tenantId);
        $this->assertSame($this->branchA->id, $context->branchId);
    }

    public function test_public_identifiers_do_not_cross_tenant_boundaries(): void
    {
        $this->assertNotSame($this->branchA->uuid, $this->branchB->uuid);
        $this->assertFalse($this->resolve()->permitsBranch($this->branchB->id));
    }

    public function test_missing_integration_context_fails_safely_and_resources_hide_ids_and_secrets(): void
    {
        try {
            app(WhatsAppChannelContextResolver::class)->resolve($this->profile->uuid, 'missing-phone');
            $this->fail('Missing provider phone must fail.');
        } catch (WhatsAppContextException $exception) {
            $this->assertSame('WHATSAPP_ACCOUNT_NOT_FOUND', $exception->errorCode);
        }

        $resource = (new WhatsAppConnectionResource($this->assignment->load(['profile', 'phoneNumber'])))->resolve(Request::create('/'));
        $encoded = json_encode($resource);
        $this->assertArrayNotHasKey('id', $resource);
        $this->assertStringNotContainsString('secret', $encoded);
        $this->assertStringNotContainsString('tenant_id', $encoded);
    }

    private function resolve()
    {
        return app(WhatsAppChannelContextResolver::class)->resolve($this->profile->uuid, $this->number->provider_phone_id);
    }
}
