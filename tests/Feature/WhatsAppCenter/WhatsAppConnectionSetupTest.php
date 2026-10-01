<?php

namespace Tests\Feature\WhatsAppCenter;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Branch\Models\Branch;
use Modules\Saas\Http\Controllers\Api\V1\SaasWhatsAppOrderingController;
use Modules\Saas\Models\Tenant;
use Modules\WhatsAppCenter\Models\WhatsAppPhoneNumber;
use Modules\WhatsAppCenter\Models\WhatsAppProviderProfile;
use Modules\WhatsAppCenter\Models\WhatsAppTenantAssignment;
use Modules\WhatsAppCenter\Services\WhatsAppConnectionDiagnostics;
use Tests\TestCase;

/**
 * The managed-number setup must explain exactly why "Hi" would not be
 * answered, block data-entry mistakes, and never expose secrets.
 */
class WhatsAppConnectionSetupTest extends TestCase
{
    use RefreshDatabase;

    private const ACCOUNT_ID = '6aabc8ecd982a2bddbd1ccb6';

    private const WABA = '375690030937364';

    private const SECRET = 'managed-webhook-secret-123456';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_wrong_account_id_blocks_the_managed_profile(): void
    {
        $this->fakeNexMsg($this->account());

        try {
            $this->storeProfile(['account_id' => '6a475d96f6286cfd5aa377b8']);
            $this->fail('A NexMsg account that does not belong to the Auth Key must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('credentials.account_id', $exception->errors());
        }

        $this->assertDatabaseCount('whatsapp_provider_profiles', 0);
    }

    public function test_waba_id_in_the_account_field_is_explained(): void
    {
        $this->fakeNexMsg($this->account());

        try {
            $this->storeProfile(['account_id' => self::WABA]);
            $this->fail('A numeric WABA ID must not be accepted as the NexMsg Account ID.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('not the numeric WABA ID', $exception->errors()['credentials.account_id'][0]);
        }
    }

    public function test_provider_not_ready_is_saved_as_failed_with_an_itemised_checklist(): void
    {
        // Exactly the live MANU state: ordering disabled, no webhook, no catalog.
        $this->fakeNexMsg($this->account([
            'catalogId' => '',
            'nexdineOrdering' => ['enabled' => false, 'webhookUrl' => '', 'secretConfigured' => false, 'lastError' => ''],
        ]));

        $response = $this->storeProfile();
        $body = $response->getData(true)['body'];
        $checks = collect($body['diagnostics']['checks'])->keyBy('key');

        $this->assertSame('failed', $body['status']);
        $this->assertFalse($body['diagnostics']['ready']);
        $this->assertSame('pass', $checks['nexmsg_account_id']['status']);
        $this->assertSame('fail', $checks['nexmsg_catalog_id']['status']);
        $this->assertSame('fail', $checks['nexmsg_ordering_enabled']['status']);
        $this->assertSame('fail', $checks['nexmsg_webhook_url']['status']);
        $this->assertSame('fail', $checks['assignment']['status']);
        $this->assertStringNotContainsString(self::SECRET, $response->getContent());
        $this->assertStringNotContainsString('valid-auth-key', $response->getContent());
        $this->assertNotNull(WhatsAppProviderProfile::query()->withoutGlobalTenant()->sole()->last_error);
    }

    public function test_ready_provider_and_active_assignment_pass_every_blocking_check(): void
    {
        $this->fakeNexMsg($this->account());
        $body = $this->storeProfile()->getData(true)['body'];
        $profile = WhatsAppProviderProfile::query()->withoutGlobalTenant()->where('uuid', $body['uuid'])->sole();
        [$tenant, $branch] = $this->tenantWithBranch();
        WhatsAppTenantAssignment::query()->withoutGlobalTenant()->create([
            'tenant_id' => $tenant->id, 'provider_profile_id' => $profile->id,
            'phone_number_id' => $profile->phoneNumbers()->value('id'), 'ownership_mode' => 'nexdine_managed',
            'allowed_branch_ids' => [$branch->id], 'capabilities' => ['ordering' => true], 'is_active' => true,
        ]);

        $report = app(WhatsAppConnectionDiagnostics::class)->forProfile($profile->fresh());

        $this->assertTrue($report['ready'], json_encode($report['checks']));
        $this->assertSame('pending', $profile->fresh()->status);
        $this->assertSame('warn', collect($report['checks'])->firstWhere('key', 'first_message')['status']);
    }

    public function test_status_cannot_be_forced_to_connected_by_an_admin(): void
    {
        $this->fakeNexMsg($this->account([
            'nexdineOrdering' => ['enabled' => false, 'webhookUrl' => '', 'secretConfigured' => false],
        ]));
        $body = $this->storeProfile()->getData(true)['body'];

        app(SaasWhatsAppOrderingController::class)->updateProfile(Request::create('/', 'PUT', [
            'name' => 'NexMsg', 'display_number' => '+91 92468 92524', 'provider_phone_id' => self::WABA,
            'status' => 'connected', 'is_active' => true,
        ]), $body['uuid']);

        $this->assertSame('failed', WhatsAppProviderProfile::query()->withoutGlobalTenant()->where('uuid', $body['uuid'])->value('status'));
    }

    public function test_changing_the_restaurant_number_keeps_ordering_rules(): void
    {
        [$tenant, $branch] = $this->tenantWithBranch();
        $old = $this->restaurantProfile($tenant, 'old-waba');
        WhatsAppTenantAssignment::query()->withoutGlobalTenant()->create([
            'tenant_id' => $tenant->id, 'provider_profile_id' => $old->id, 'phone_number_id' => $old->phoneNumbers()->value('id'),
            'ownership_mode' => 'restaurant_owned', 'allowed_branch_ids' => [$branch->id], 'is_active' => true,
            'capabilities' => ['ordering' => true, 'human_handoff' => false, 'greeting_keywords' => ['namaste'], 'enabled_order_types' => ['takeaway', 'delivery']],
        ]);

        $user = $this->tenantAdmin($tenant);
        $this->actingAs($user, 'api');
        app(\Modules\WhatsAppCenter\Http\Controllers\Api\V1\WhatsAppOrderingController::class)->connect($this->connectRequest($user, '112233445566'));

        $active = WhatsAppTenantAssignment::query()->withoutGlobalTenant()->where('tenant_id', $tenant->id)->where('is_active', true)->sole();
        $this->assertSame(['namaste'], $active->capabilities['greeting_keywords']);
        $this->assertSame(['takeaway', 'delivery'], $active->capabilities['enabled_order_types']);
        $this->assertFalse($active->capabilities['human_handoff']);
        $this->assertTrue($active->capabilities['ordering']);
    }

    public function test_restaurant_cannot_connect_a_number_owned_by_another_profile(): void
    {
        [$tenant] = $this->tenantWithBranch();
        [$other] = $this->tenantWithBranch();
        $this->restaurantProfile($other, 'shared-waba');
        $user = $this->tenantAdmin($tenant);
        $this->actingAs($user, 'api');

        try {
            app(\Modules\WhatsAppCenter\Http\Controllers\Api\V1\WhatsAppOrderingController::class)->connect($this->connectRequest($user, 'shared-waba'));
            $this->fail('A number already routed to another profile must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('provider_phone_id', $exception->errors());
        }
        $this->assertSame(0, WhatsAppTenantAssignment::query()->withoutGlobalTenant()->where('tenant_id', $tenant->id)->count());
    }

    private function storeProfile(array $credentials = [])
    {
        return app(SaasWhatsAppOrderingController::class)->storeProfile(Request::create('/', 'POST', [
            'name' => 'NexMsg', 'provider' => 'nexmsg', 'provider_phone_id' => self::WABA, 'display_number' => '+91 92468 92524',
            'credentials' => array_replace([
                'auth_key' => 'valid-auth-key', 'account_id' => self::ACCOUNT_ID,
                'catalog_id' => '1613024323799809', 'webhook_secret' => self::SECRET,
            ], $credentials),
        ]));
    }

    private function connectRequest($user, string $providerPhoneId): Request
    {
        $request = Request::create('/', 'POST', [
            'provider' => 'msg91', 'name' => 'Own number', 'display_number' => '+91 90000 00001',
            'provider_phone_id' => $providerPhoneId,
            'credentials' => ['auth_key' => 'msg91-auth-key', 'webhook_secret' => self::SECRET],
        ]);
        $request->setUserResolver(fn () => $user);

        return $request;
    }

    private function fakeNexMsg(array $account): void
    {
        Http::fake(['api-nexmsg.myteknoland.com/api/accounts' => Http::response([$account])]);
    }

    private function account(array $overrides = []): array
    {
        return array_replace([
            'id' => self::ACCOUNT_ID, 'wabaId' => self::WABA, 'displayPhone' => '+91 92468 92524',
            'catalogId' => '1613024323799809',
            'nexdineOrdering' => [
                'enabled' => true, 'secretConfigured' => true,
                'webhookUrl' => 'https://api.nexdine.myteknoland.in/v1/whatsapp/webhook/nexmsg',
            ],
        ], $overrides);
    }

    private function tenantWithBranch(): array
    {
        $tenant = Tenant::query()->withoutGlobalScopes()->create([
            'name' => 'Restaurant '.Str::random(4), 'slug' => 'restaurant-'.Str::lower(Str::random(8)), 'is_active' => true,
        ]);
        $branch = Branch::factory()->create(['tenant_id' => $tenant->id, 'is_active' => true]);

        return [$tenant, $branch];
    }

    private function restaurantProfile(Tenant $tenant, string $providerPhoneId): WhatsAppProviderProfile
    {
        $profile = WhatsAppProviderProfile::query()->withoutGlobalTenant()->create([
            'tenant_id' => $tenant->id, 'name' => 'Own', 'ownership_mode' => 'restaurant_owned', 'provider' => 'msg91',
            'credentials' => ['auth_key' => 'old', 'webhook_secret' => self::SECRET], 'status' => 'connected', 'is_active' => true,
        ]);
        WhatsAppPhoneNumber::query()->create([
            'provider_profile_id' => $profile->id, 'provider_phone_id' => $providerPhoneId,
            'display_number' => '+91 90000 0000'.random_int(2, 9), 'status' => 'connected', 'is_active' => true,
        ]);

        return $profile;
    }

    private function tenantAdmin(Tenant $tenant)
    {
        $user = \Modules\User\Models\User::query()->create([
            'name' => 'Owner', 'username' => 'owner_'.Str::lower(Str::random(10)),
            'email' => Str::lower(Str::random(10)).'@example.test', 'password' => bcrypt('secret-Passw0rd'),
            'is_active' => true, 'can_login' => true,
        ]);
        $user->forceFill(['tenant_id' => $tenant->id])->saveQuietly();

        return $user->refresh();
    }
}
