<?php

namespace Tests\Feature\Notification;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Notification\Services\TenantMailerService;
use Modules\Saas\Models\Tenant;
use Modules\Setting\Models\Setting;
use Modules\User\Models\User;
use Tests\Support\AggregatorTestSupport;
use Tests\TestCase;

class TenantMailSettingsApiTest extends TestCase
{
    use AggregatorTestSupport;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAggregatorTestSupport();
    }

    public function test_tenant_admin_saves_own_mail_server_without_exposing_password(): void
    {
        $actor = $this->tenantAdmin('mail-a');

        $this->actingAs($actor, 'api')
            ->putJson('/api/v1/tenant-mail-settings', $this->gmail())
            ->assertOk()
            ->assertJsonPath('body.enabled', true)
            ->assertJsonPath('body.host', 'smtp.gmail.com')
            ->assertJsonPath('body.has_password', true)
            ->assertJsonMissingPath('body.password');

        $stored = Setting::query()->where('setting_scope', "tenant:{$actor->tenant_id}")
            ->where('key', 'tenant_smtp_password')->firstOrFail();
        $this->assertTrue($stored->is_encryptable);
        $this->assertNotSame('app-password-1234', $stored->getRawOriginal('payload'));
        $this->assertSame('app-password-1234', $stored->payload);

        // A blank password on a later save keeps the stored one.
        $this->actingAs($actor, 'api')
            ->putJson('/api/v1/tenant-mail-settings', [...$this->gmail(), 'password' => '', 'from_name' => 'CRT Travels'])
            ->assertOk()
            ->assertJsonPath('body.from_name', 'CRT Travels');
        $this->assertSame('app-password-1234', app(TenantMailerService::class)->settings($actor->tenant_id)['password']);
    }

    public function test_mail_servers_are_isolated_between_tenants(): void
    {
        $actorA = $this->tenantAdmin('mail-iso-a');
        $actorB = $this->tenantAdmin('mail-iso-b');

        $this->actingAs($actorA, 'api')->putJson('/api/v1/tenant-mail-settings', $this->gmail())->assertOk();

        $this->actingAs($actorB, 'api')
            ->getJson('/api/v1/tenant-mail-settings')
            ->assertOk()
            ->assertJsonPath('body.enabled', false)
            ->assertJsonPath('body.host', '')
            ->assertJsonPath('body.has_password', false);

        $mailers = app(TenantMailerService::class);
        $this->assertSame('agency@gmail.com', $mailers->senderAddress($actorA->tenant_id));
        $this->assertNull($mailers->senderAddress($actorB->tenant_id));
    }

    public function test_enabling_requires_server_details_and_first_password(): void
    {
        $actor = $this->tenantAdmin('mail-validate');

        $this->actingAs($actor, 'api')
            ->putJson('/api/v1/tenant-mail-settings', ['enabled' => true])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['host', 'port', 'username', 'password', 'from_address']);

        // Switching it off needs nothing else.
        $this->actingAs($actor, 'api')
            ->putJson('/api/v1/tenant-mail-settings', ['enabled' => false])
            ->assertOk();
    }

    public function test_connection_test_reports_smtp_failures(): void
    {
        $actor = $this->tenantAdmin('mail-test');

        $this->actingAs($actor, 'api')
            ->postJson('/api/v1/tenant-mail-settings/test', [
                ...$this->gmail(),
                // Nothing listens here, so the SMTP connection fails fast.
                'host' => '127.0.0.1',
                'port' => 1,
                'test_recipient' => 'owner@example.test',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['connection']);
    }

    public function test_platform_account_without_tenant_is_refused(): void
    {
        $actor = $this->actingAsUserWithPermissions(['admin.settings.edit']);

        $this->actingAs($actor, 'api')
            ->getJson('/api/v1/tenant-mail-settings')
            ->assertForbidden();
    }

    private function gmail(): array
    {
        return [
            'enabled' => true,
            'host' => 'smtp.gmail.com',
            'port' => 587,
            'username' => 'agency@gmail.com',
            'password' => 'app-password-1234',
            'encryption' => 'tls',
            'from_address' => 'agency@gmail.com',
            'from_name' => '',
        ];
    }

    private function tenantAdmin(string $slug): User
    {
        $tenant = Tenant::query()->withoutGlobalScopes()->create([
            'name' => $slug,
            'slug' => $slug,
            'domain' => $slug.'.example.test',
            'is_active' => true,
        ]);
        $branch = $this->makeBranch(['tenant_id' => $tenant->id]);
        $actor = $this->actingAsUserWithPermissions(['admin.settings.edit']);
        $actor->forceFill(['tenant_id' => $tenant->id, 'branch_id' => $branch->id])->save();

        return $actor->refresh();
    }
}
