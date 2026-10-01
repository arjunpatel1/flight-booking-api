<?php

namespace Tests\Feature\WhatsAppCenter;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Branch\Models\Branch;
use Modules\Product\Models\Product;
use Modules\Saas\Models\Tenant;
use Modules\WhatsAppCenter\Models\WhatsAppCatalogProduct;
use Modules\WhatsAppCenter\Models\WhatsAppConversation;
use Modules\WhatsAppCenter\Models\WhatsAppOrderSession;
use Modules\WhatsAppCenter\Models\WhatsAppPhoneNumber;
use Modules\WhatsAppCenter\Models\WhatsAppProviderProfile;
use Modules\WhatsAppCenter\Models\WhatsAppTenantAssignment;
use Modules\WhatsAppCenter\Services\WhatsAppBranchTransition;
use Tests\TestCase;

class WhatsAppBranchTransitionTest extends TestCase
{
    use RefreshDatabase;

    public function test_moving_a_number_retires_old_branch_runtime_state_without_deleting_history(): void
    {
        $tenant = Tenant::query()->withoutGlobalScopes()->create([
            'name' => 'Branch Move Restaurant', 'slug' => 'branch-move-'.Str::lower(Str::random(8)), 'is_active' => true,
        ]);
        $oldBranch = Branch::factory()->create(['tenant_id' => $tenant->id, 'is_active' => true]);
        $newBranch = Branch::factory()->create(['tenant_id' => $tenant->id, 'is_active' => true]);
        $profile = WhatsAppProviderProfile::query()->withoutGlobalTenant()->create([
            'tenant_id' => $tenant->id, 'name' => 'Managed number', 'ownership_mode' => 'nexdine_managed',
            'provider' => 'nexmsg', 'credentials' => ['catalog_id' => 'catalog-1'], 'status' => 'connected', 'is_active' => true,
        ]);
        $number = WhatsAppPhoneNumber::query()->create([
            'provider_profile_id' => $profile->id, 'provider_phone_id' => 'branch-move-phone',
            'display_number' => '+919000000001', 'status' => 'connected', 'is_active' => true,
        ]);
        $assignment = WhatsAppTenantAssignment::query()->withoutGlobalTenant()->create([
            'tenant_id' => $tenant->id, 'provider_profile_id' => $profile->id, 'phone_number_id' => $number->id,
            'ownership_mode' => 'nexdine_managed', 'allowed_branch_ids' => [$oldBranch->id],
            'capabilities' => ['ordering' => true], 'is_active' => true,
        ]);
        $conversation = WhatsAppConversation::query()->withoutGlobalTenant()->create([
            'uuid' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'branch_id' => $oldBranch->id,
            'assignment_id' => $assignment->id, 'customer_phone' => '919999999999', 'state' => 'bot',
        ]);
        $session = WhatsAppOrderSession::query()->withoutGlobalTenant()->create([
            'uuid' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'branch_id' => $oldBranch->id,
            'conversation_id' => $conversation->id, 'cart_uuid' => (string) Str::uuid(), 'state' => 'browsing',
        ]);
        $product = Product::factory()->create();
        $mapping = WhatsAppCatalogProduct::query()->withoutGlobalTenant()->create([
            'tenant_id' => $tenant->id, 'branch_id' => $oldBranch->id, 'provider_profile_id' => $profile->id,
            'provider' => 'nexmsg', 'catalog_id' => 'catalog-1', 'product_id' => $product->id,
            'product_retailer_id' => (string) $product->uuid, 'status' => 'active', 'sync_status' => 'synced',
        ]);

        $result = app(WhatsAppBranchTransition::class)->retireRemovedBranches($assignment, [$newBranch->id]);

        $this->assertSame([$oldBranch->id], $result['removed_branch_ids']);
        $this->assertSame([$mapping->id], $result['catalog_mapping_ids']);
        $this->assertSame(1, $result['closed_conversations']);
        $this->assertSame(1, $result['expired_sessions']);
        $this->assertDatabaseHas('whatsapp_conversations', ['id' => $conversation->id, 'state' => 'closed']);
        $this->assertDatabaseHas('whatsapp_order_sessions', ['id' => $session->id, 'state' => 'expired']);
        $this->assertDatabaseHas('whatsapp_catalog_products', [
            'id' => $mapping->id, 'status' => 'disabled', 'sync_status' => 'pending',
        ]);
    }

    public function test_retaining_the_same_branch_does_not_retire_any_state(): void
    {
        $tenant = Tenant::query()->withoutGlobalScopes()->create([
            'name' => 'Stable Restaurant', 'slug' => 'stable-'.Str::lower(Str::random(8)), 'is_active' => true,
        ]);
        $branch = Branch::factory()->create(['tenant_id' => $tenant->id, 'is_active' => true]);
        $profile = WhatsAppProviderProfile::query()->withoutGlobalTenant()->create([
            'tenant_id' => $tenant->id, 'name' => 'Managed number', 'ownership_mode' => 'nexdine_managed',
            'provider' => 'nexmsg', 'credentials' => [], 'status' => 'connected', 'is_active' => true,
        ]);
        $number = WhatsAppPhoneNumber::query()->create([
            'provider_profile_id' => $profile->id, 'provider_phone_id' => 'stable-phone',
            'display_number' => '+919000000002', 'status' => 'connected', 'is_active' => true,
        ]);
        $assignment = WhatsAppTenantAssignment::query()->withoutGlobalTenant()->create([
            'tenant_id' => $tenant->id, 'provider_profile_id' => $profile->id, 'phone_number_id' => $number->id,
            'ownership_mode' => 'nexdine_managed', 'allowed_branch_ids' => [$branch->id],
            'capabilities' => ['ordering' => true], 'is_active' => true,
        ]);

        $result = app(WhatsAppBranchTransition::class)->retireRemovedBranches($assignment, [$branch->id]);

        $this->assertSame([], $result['removed_branch_ids']);
        $this->assertSame([], $result['catalog_mapping_ids']);
    }
}
