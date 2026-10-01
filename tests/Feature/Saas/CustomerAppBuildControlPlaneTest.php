<?php

namespace Tests\Feature\Saas;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Auth\Middleware\Authorize;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Modules\Saas\Exceptions\CustomerAppAuthorizationException;
use Modules\Saas\Http\Middleware\EnsureAuthenticatedTenant;
use Modules\Saas\Models\CustomerAppBuild;
use Modules\Saas\Models\CustomerAppRegistration;
use Modules\Saas\Models\Tenant;
use Modules\Saas\Services\CustomerApp\CustomerAppAuthorizationService;
use Modules\Saas\Services\CustomerApp\CustomerAppBuildSnapshotValidator;
use Modules\User\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CustomerAppBuildControlPlaneTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenantA;

    private Tenant $tenantB;

    private User $administratorA;

    protected function setUp(): void
    {
        parent::setUp();
        activity()->disableLogging();
        config([
            'saas.customer_app.api_origin' => 'https://api.example.test/v1',
            'saas.customer_app_build.worker_connected' => false,
            'saas.customer_app_build.require_splash' => false,
            'saas.customer_app_build.repository' => 'https://github.com/example/nexdine-customer-app.git',
            'saas.customer_app_build.source_commit' => str_repeat('a', 40),
            'saas.customer_app_build.flutter_version' => '3.35.1',
            'saas.customer_app_build.android_sdk_version' => '35',
            'saas.customer_app_build.signer_sha256' => str_repeat('b', 64),
            'saas.customer_app_build.supports_aab' => false,
            'saas.customer_app_build.worker_token' => str_repeat('w', 48),
            'saas.customer_app_build.max_active_per_tenant' => 1,
            'saas.customer_app_build.max_attempts' => 3,
            'saas.customer_app_build.lease_seconds' => 60,
        ]);

        $this->tenantA = $this->tenant('tenant-a');
        $this->tenantB = $this->tenant('tenant-b');
        $this->entitle($this->tenantA);
        $this->entitle($this->tenantB);
        $this->registration($this->tenantA, 'com.nexdine.customer.tenanta');
        $this->registration($this->tenantB, 'com.nexdine.customer.tenantb');
        $this->administratorA = $this->user($this->tenantA, 'owner-a@example.test');

        Sanctum::actingAs($this->administratorA);
        $this->withoutMiddleware(EnsureAuthenticatedTenant::class);
        // Permission boundaries are covered by the dedicated SaaS control-plane
        // contract. This class exercises build lifecycle and tenant ownership.
        $this->withoutMiddleware(Authorize::class);
        $this->allowBuildAuthorization();
    }

    protected function tearDown(): void
    {
        activity()->enableLogging();
        parent::tearDown();
    }

    public function test_build_request_is_tenant_scoped_secret_free_and_honestly_queued(): void
    {
        $response = $this->postJson($this->endpoint(), $this->validPayload())
            ->assertOk()
            ->assertJsonPath('body.reused', false)
            ->assertJsonPath('body.build.status', 'queued')
            ->assertJsonPath('body.build.worker_connected', false);

        $build = CustomerAppBuild::query()->firstOrFail();
        $this->assertSame($this->tenantA->id, $build->tenant_id);
        $this->assertSame('Build service not connected. Request is safely queued.', $response->json('body.build.status_message'));
        $snapshot = json_encode($build->config_snapshot, JSON_THROW_ON_ERROR);
        foreach (['password', 'secret', 'private_key', 'database', 'app_key', 'token'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, strtolower($snapshot));
        }
        $this->assertNull($response->json('body.build.artifact'));
    }

    public function test_same_active_request_is_idempotent_and_capacity_blocks_parallel_versions(): void
    {
        $first = $this->postJson($this->endpoint(), $this->validPayload())->assertOk()->json('body.build.uuid');
        $second = $this->postJson($this->endpoint(), $this->validPayload())
            ->assertOk()->assertJsonPath('body.reused', true)->json('body.build.uuid');
        CustomerAppRegistration::query()
            ->where('tenant_id', $this->tenantA->id)
            ->update(['branding_revision' => 2]);
        $this->postJson($this->endpoint(), $this->validPayload())
            ->assertStatus(429)
            ->assertJsonPath('body.code', 'TENANT_BUILD_CAPACITY_REACHED');

        $this->assertSame($first, $second);
        $this->assertSame(1, CustomerAppBuild::query()->count());

        CustomerAppBuild::query()->where('uuid', $first)->firstOrFail()->forceFill([
            'status' => CustomerAppBuild::STATUS_FAILED,
            'active_fingerprint' => null,
            'failed_at' => now(),
        ])->save();

        $third = $this->postJson($this->endpoint(), $this->validPayload())
            ->assertOk()->assertJsonPath('body.reused', false)->json('body.build.uuid');

        $this->assertNotSame($first, $third);
        $this->assertSame(2, CustomerAppBuild::query()->count());
    }

    public function test_forged_tenant_and_registration_authority_fields_are_rejected(): void
    {
        $this->postJson($this->endpoint(), $this->validPayload() + ['tenant_id' => $this->tenantB->id])
            ->assertUnprocessable()->assertJsonValidationErrors(['tenant_id']);
        $this->postJson($this->endpoint(), $this->validPayload() + ['customer_app_registration_id' => 999])
            ->assertUnprocessable()->assertJsonValidationErrors(['customer_app_registration_id']);
        $this->assertDatabaseCount('customer_app_builds', 0);
    }

    #[DataProvider('foreignOperationProvider')]
    public function test_tenant_a_cannot_operate_on_tenant_b_build(string $method, string $suffix): void
    {
        $foreign = $this->build($this->tenantB, 'failed');
        $url = $this->endpoint().'/'.$foreign->uuid.$suffix;
        $response = $method === 'GET' ? $this->getJson($url) : $this->postJson($url);

        $response->assertNotFound()->assertJsonPath('body.code', 'BUILD_NOT_FOUND');
        $this->assertSame('failed', $foreign->fresh()->status);
    }

    public static function foreignOperationProvider(): array
    {
        return [
            'view' => ['GET', ''],
            'download' => ['GET', '/artifact'],
            'cancel' => ['POST', '/cancel'],
            'retry' => ['POST', '/retry'],
        ];
    }

    public function test_foreign_create_is_impossible_even_when_client_supplies_foreign_identity(): void
    {
        $this->postJson($this->endpoint(), $this->validPayload() + ['tenant_id' => $this->tenantB->id])
            ->assertUnprocessable();
        $this->assertDatabaseMissing('customer_app_builds', ['tenant_id' => $this->tenantB->id]);
    }

    public function test_inactive_registration_and_missing_branding_are_denied(): void
    {
        DB::table('customer_app_registrations')->where('tenant_id', $this->tenantA->id)->update(['status' => 'inactive']);
        $this->postJson($this->endpoint(), $this->validPayload())
            ->assertForbidden()->assertJsonPath('body.code', 'APP_INACTIVE');

        DB::table('customer_app_registrations')->where('tenant_id', $this->tenantA->id)->update(['status' => 'active']);
        $this->tenantA->forceFill(['settings' => []])->save();
        $this->postJson($this->endpoint(), $this->validPayload())
            ->assertUnprocessable()->assertJsonPath('body.code', 'BRANDING_INCOMPLETE');
    }

    public function test_inactive_tenant_disabled_entitlement_and_unauthorized_role_are_denied(): void
    {
        $authorization = Mockery::mock(CustomerAppAuthorizationService::class);
        $authorization->shouldReceive('assertBuildAccess')->andThrowExceptions([
            new CustomerAppAuthorizationException('TENANT_SUSPENDED', 'Denied for test.'),
            new CustomerAppAuthorizationException('PLAN_FEATURE_DISABLED', 'Denied for test.'),
            new CustomerAppAuthorizationException('ROLE_FORBIDDEN', 'Denied for test.'),
        ]);
        $this->app->instance(CustomerAppAuthorizationService::class, $authorization);

        $this->postJson($this->endpoint(), $this->validPayload())
            ->assertForbidden()->assertJsonPath('body.code', 'TENANT_SUSPENDED');

        $this->postJson($this->endpoint(), $this->validPayload())
            ->assertForbidden()->assertJsonPath('body.code', 'PLAN_FEATURE_DISABLED');

        $this->postJson($this->endpoint(), $this->validPayload())
            ->assertForbidden()->assertJsonPath('body.code', 'ROLE_FORBIDDEN');
    }

    public function test_invalid_platform_build_type_version_and_bundle_combination_are_denied(): void
    {
        $this->postJson($this->endpoint(), array_replace($this->validPayload(), ['platform' => 'windows']))
            ->assertUnprocessable()->assertJsonValidationErrors(['platform']);
        $this->postJson($this->endpoint(), array_replace($this->validPayload(), ['build_type' => 'debug']))
            ->assertUnprocessable()->assertJsonValidationErrors(['build_type']);
        $this->postJson($this->endpoint(), array_replace($this->validPayload(), ['requested_version' => 'latest']))
            ->assertUnprocessable()->assertJsonValidationErrors(['requested_version']);
        $this->postJson($this->endpoint(), ['platform' => 'ios', 'build_type' => 'app_bundle', 'requested_version' => '1.0.0'])
            ->assertUnprocessable()->assertJsonPath('body.code', 'INVALID_BUILD_COMBINATION');
    }

    public function test_artifact_contract_is_private_tenant_bound_and_not_a_public_url(): void
    {
        $build = $this->build($this->tenantA, 'ready');
        DB::table('customer_app_build_artifacts')->insert([
            'uuid' => (string) Str::uuid(), 'customer_app_build_id' => $build->id,
            'tenant_id' => $this->tenantA->id, 'platform' => 'android', 'build_type' => 'release',
            'version' => '1.0.0', 'checksum' => str_repeat('a', 64), 'storage_disk' => 'local',
            'storage_reference' => 'private/customer-app/'.$build->uuid.'/app.apk', 'size_bytes' => 123,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $payload = $this->getJson($this->endpoint().'/'.$build->uuid)->assertOk()->json('body.build');
        $this->assertArrayNotHasKey('storage_reference', $payload['artifact']);
        $this->assertArrayNotHasKey('storage_disk', $payload['artifact']);
        $this->assertArrayNotHasKey('download_url', $payload['artifact']);
    }

    public function test_build_migrations_are_indexed_foreign_key_safe_and_reversible(): void
    {
        $indexes = collect(Schema::getIndexes('customer_app_builds'))->pluck('name');
        $this->assertContains('customer_app_build_tenant_status_idx', $indexes);
        $this->assertContains('customer_app_build_registration_request_idx', $indexes);
        $this->assertContains('customer_app_build_tenant_owner_unique', $indexes);
        $this->assertNotEmpty(Schema::getForeignKeys('customer_app_builds'));
        $this->assertNotEmpty(Schema::getForeignKeys('customer_app_build_artifacts'));

        $artifacts = require base_path('Modules/Saas/database/migrations/2026_08_11_000004_create_customer_app_build_artifacts_table.php');
        $builds = require base_path('Modules/Saas/database/migrations/2026_08_11_000003_create_customer_app_builds_table.php');
        $hardening = require base_path('Modules/Saas/database/migrations/2026_08_12_000001_harden_customer_app_build_worker.php');
        $hardening->down();
        $artifacts->down();
        $builds->down();
        $this->assertFalse(Schema::hasTable('customer_app_builds'));
        $builds->up();
        $artifacts->up();
        $hardening->up();
        $this->assertTrue(Schema::hasTable('customer_app_build_artifacts'));
        $this->assertContains('customer_app_build_worker_claim_idx', collect(Schema::getIndexes('customer_app_builds'))->pluck('name'));
    }

    public function test_worker_requires_a_strong_bearer_token_and_identity_header(): void
    {
        $this->postJson('/api/v1/saas/customer-app-build-worker/claim')->assertUnauthorized();
        $this->withHeaders(['Authorization' => 'Bearer wrong', 'X-Build-Worker-ID' => 'gha-1'])
            ->postJson('/api/v1/saas/customer-app-build-worker/claim')->assertUnauthorized();

        // withHeaders() merges into the test client's persistent defaults. Clear the
        // prior worker identity so this request genuinely exercises the missing-ID path.
        $this->flushHeaders();

        $this->withHeaders(['Authorization' => 'Bearer '.str_repeat('w', 48)])
            ->postJson('/api/v1/saas/customer-app-build-worker/claim')->assertUnprocessable();
    }

    public function test_worker_claim_is_leased_and_exposes_only_the_immutable_build_contract(): void
    {
        $this->postJson($this->endpoint(), $this->validPayload())->assertOk();

        $build = $this->withHeaders($this->workerHeaders())->postJson('/api/v1/saas/customer-app-build-worker/claim')
            ->assertOk()->assertJsonPath('body.build.status', 'claimed')->json('body.build');

        $this->assertArrayNotHasKey('tenant_id', $build);
        $this->assertArrayNotHasKey('requested_by', $build);
        $this->assertSame(1, $build['attempt']);
        $this->assertSame('claimed', CustomerAppBuild::query()->where('uuid', $build['uuid'])->value('status'));
    }

    public function test_worker_terminally_fails_an_expired_build_after_attempt_budget_is_exhausted(): void
    {
        $build = $this->build($this->tenantA, CustomerAppBuild::STATUS_CLAIMED);
        $build->forceFill([
            'worker_id' => 'stale-worker',
            'attempt' => 3,
            'lease_expires_at' => now()->subMinute(),
        ])->save();

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/v1/saas/customer-app-build-worker/claim')
            ->assertOk()
            ->assertJsonPath('body.build', null);

        $build->refresh();
        $this->assertSame(CustomerAppBuild::STATUS_FAILED, $build->status);
        $this->assertSame('BUILD_ATTEMPTS_EXHAUSTED', $build->error_code);
        $this->assertNull($build->worker_id);
        $this->assertNull($build->lease_expires_at);
        $this->assertNull($build->active_fingerprint);
    }

    public function test_worker_finalizes_an_expired_cancellation_without_reclaiming_it(): void
    {
        $build = $this->build($this->tenantA, CustomerAppBuild::STATUS_CANCEL_REQUESTED);
        $build->forceFill([
            'worker_id' => 'stale-worker',
            'attempt' => 1,
            'lease_expires_at' => now()->subMinute(),
        ])->save();

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/v1/saas/customer-app-build-worker/claim')
            ->assertOk()
            ->assertJsonPath('body.build', null);

        $build->refresh();
        $this->assertSame(CustomerAppBuild::STATUS_CANCELLED, $build->status);
        $this->assertNotNull($build->cancelled_at);
        $this->assertNull($build->worker_id);
        $this->assertNull($build->lease_expires_at);
        $this->assertNull($build->active_fingerprint);
    }

    public function test_worker_heartbeat_terminally_fails_a_build_past_the_absolute_runtime_limit(): void
    {
        config(['saas.customer_app_build.max_runtime_seconds' => 300]);

        $build = $this->build($this->tenantA, CustomerAppBuild::STATUS_CLAIMED);
        $build->forceFill([
            'worker_id' => 'gha-1',
            'claimed_at' => now()->subMinutes(6),
            'lease_expires_at' => now()->addMinute(),
        ])->save();

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/v1/saas/customer-app-build-worker/builds/'.$build->uuid.'/heartbeat')
            ->assertOk()
            ->assertJsonPath('body.build.status', CustomerAppBuild::STATUS_FAILED)
            ->assertJsonPath('body.build.error_code', 'BUILD_RUNTIME_EXCEEDED');

        $build->refresh();
        $this->assertNotNull($build->failed_at);
        $this->assertNull($build->worker_id);
        $this->assertNull($build->lease_expires_at);
        $this->assertNull($build->active_fingerprint);
    }

    public function test_worker_quarantines_a_tampered_tenant_bound_snapshot(): void
    {
        $build = $this->build($this->tenantA, 'queued');
        $snapshot = $build->config_snapshot;
        data_set($snapshot, 'tenant.uuid', $this->tenantB->uuid);
        $build->forceFill(['config_snapshot' => $snapshot])->save();

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/v1/saas/customer-app-build-worker/claim')
            ->assertOk()
            ->assertJsonPath('body.build', null);

        $build->refresh();
        $this->assertSame('failed', $build->status);
        $this->assertSame('BUILD_SNAPSHOT_INVALID', $build->error_code);
        $this->assertNotNull($build->failed_at);
    }

    public function test_worker_rejects_invalid_transitions_and_wrong_artifact_identity(): void
    {
        $this->postJson($this->endpoint(), $this->validPayload())->assertOk();
        $claimed = $this->withHeaders($this->workerHeaders())->postJson('/api/v1/saas/customer-app-build-worker/claim')
            ->assertOk()->json('body.build');
        $base = '/api/v1/saas/customer-app-build-worker/builds/'.$claimed['uuid'];

        $this->withHeaders($this->workerHeaders())->postJson($base.'/transition', ['status' => 'testing'])
            ->assertStatus(409)->assertJsonPath('body.code', 'INVALID_BUILD_TRANSITION');

        foreach (['building', 'testing', 'signing', 'verifying', 'uploading'] as $status) {
            $this->withHeaders($this->workerHeaders())->postJson($base.'/transition', ['status' => $status])->assertOk();
        }
        $artifact = UploadedFile::fake()->createWithContent('app.apk', str_repeat('a', 2048));
        $this->withHeaders($this->workerHeaders())->post($base.'/complete', [
            'artifact' => $artifact,
            'checksum' => hash_file('sha256', $artifact->getRealPath()),
            'package_id' => 'com.attacker.foreign',
            'commit' => str_repeat('a', 40),
            'signer_sha256' => str_repeat('b', 64),
            'version' => '1.0.0',
            'application_label' => $this->tenantA->name,
        ], ['Accept' => 'application/json'])->assertUnprocessable()
            ->assertJsonPath('body.code', 'ARTIFACT_IDENTITY_MISMATCH');
    }

    public function test_verified_worker_artifact_is_written_only_to_private_storage(): void
    {
        Storage::fake('local');
        $this->postJson($this->endpoint(), $this->validPayload())->assertOk();
        $claimed = $this->withHeaders($this->workerHeaders())->postJson('/api/v1/saas/customer-app-build-worker/claim')
            ->assertOk()->json('body.build');
        $base = '/api/v1/saas/customer-app-build-worker/builds/'.$claimed['uuid'];
        foreach (['building', 'testing', 'signing', 'verifying', 'uploading'] as $status) {
            $this->withHeaders($this->workerHeaders())->postJson($base.'/transition', ['status' => $status])->assertOk();
        }
        $artifact = UploadedFile::fake()->createWithContent('app.apk', str_repeat('z', 2048));
        $response = $this->withHeaders($this->workerHeaders())->post($base.'/complete', [
            'artifact' => $artifact,
            'checksum' => hash_file('sha256', $artifact->getRealPath()),
            'package_id' => 'com.nexdine.customer.tenanta',
            'commit' => str_repeat('a', 40),
            'signer_sha256' => str_repeat('b', 64),
            'version' => '1.0.0',
            'application_label' => $this->tenantA->name,
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('body.build.status', 'ready');

        $stored = CustomerAppBuild::query()->where('uuid', $claimed['uuid'])->firstOrFail()->artifact;
        Storage::disk('local')->assertExists($stored->storage_reference);
        $this->assertStringStartsWith('private/customer-app/', $stored->storage_reference);
        $this->assertNotNull($stored->verified_at);
        $this->assertArrayNotHasKey('storage_reference', $response->json('body.build.artifact'));
        $this->assertSame(
            str_repeat('b', 64),
            $stored->build->registration()->withoutGlobalScopes()->value('signing_certificate_fingerprint'),
        );
    }

    public function test_worker_rejects_an_artifact_signed_by_an_unpinned_certificate(): void
    {
        Storage::fake('local');
        $this->postJson($this->endpoint(), $this->validPayload())->assertOk();
        $claimed = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/v1/saas/customer-app-build-worker/claim')
            ->assertOk()->json('body.build');
        $base = '/api/v1/saas/customer-app-build-worker/builds/'.$claimed['uuid'];

        foreach (['building', 'testing', 'signing', 'verifying', 'uploading'] as $status) {
            $this->withHeaders($this->workerHeaders())->postJson($base.'/transition', ['status' => $status])->assertOk();
        }

        $artifact = UploadedFile::fake()->createWithContent('app.apk', str_repeat('s', 2048));
        $this->withHeaders($this->workerHeaders())->post($base.'/complete', [
            'artifact' => $artifact,
            'checksum' => hash_file('sha256', $artifact->getRealPath()),
            'package_id' => 'com.nexdine.customer.tenanta',
            'commit' => str_repeat('a', 40),
            'signer_sha256' => str_repeat('c', 64),
            'version' => '1.0.0',
            'application_label' => $this->tenantA->name,
        ], ['Accept' => 'application/json'])->assertUnprocessable()
            ->assertJsonPath('body.code', 'ARTIFACT_SIGNATURE_INVALID');

        $this->assertDatabaseMissing('customer_app_build_artifacts', ['customer_app_build_id' => $claimed['id'] ?? 0]);
    }

    public function test_worker_rejects_version_and_application_label_drift(): void
    {
        Storage::fake('local');
        $this->postJson($this->endpoint(), $this->validPayload())->assertOk();
        $claimed = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/v1/saas/customer-app-build-worker/claim')
            ->assertOk()->json('body.build');
        $base = '/api/v1/saas/customer-app-build-worker/builds/'.$claimed['uuid'];

        foreach (['building', 'testing', 'signing', 'verifying', 'uploading'] as $status) {
            $this->withHeaders($this->workerHeaders())->postJson($base.'/transition', ['status' => $status])->assertOk();
        }

        $artifact = UploadedFile::fake()->createWithContent('app.apk', str_repeat('i', 2048));
        $this->withHeaders($this->workerHeaders())->post($base.'/complete', [
            'artifact' => $artifact,
            'checksum' => hash_file('sha256', $artifact->getRealPath()),
            'package_id' => 'com.nexdine.customer.tenanta',
            'commit' => str_repeat('a', 40),
            'signer_sha256' => str_repeat('b', 64),
            'version' => '1.0.1',
            'application_label' => 'Foreign Restaurant',
        ], ['Accept' => 'application/json'])->assertUnprocessable()
            ->assertJsonPath('body.code', 'ARTIFACT_IDENTITY_MISMATCH');
    }

    private function endpoint(): string
    {
        return '/api/v1/tenants/current/customer-app/builds';
    }

    private function validPayload(string $version = '1.0.0'): array
    {
        return ['platform' => 'android', 'build_type' => 'release', 'requested_version' => $version];
    }

    private function allowBuildAuthorization(): void
    {
        $authorization = Mockery::mock(CustomerAppAuthorizationService::class);
        $authorization->shouldReceive('assertBuildAccess')->andReturnNull();
        $this->app->instance(CustomerAppAuthorizationService::class, $authorization);
    }

    private function denyBuildAuthorization(string $code): void
    {
        $authorization = Mockery::mock(CustomerAppAuthorizationService::class);
        $authorization->shouldReceive('assertBuildAccess')->andThrow(
            new CustomerAppAuthorizationException($code, 'Denied for test.')
        );
        $this->app->instance(CustomerAppAuthorizationService::class, $authorization);
    }

    private function tenant(string $slug): Tenant
    {
        $id = DB::table('tenants')->insertGetId([
            'uuid' => (string) Str::uuid(), 'name' => $slug, 'slug' => $slug,
            'domain' => $slug.'.example.test', 'is_active' => true,
            'settings' => json_encode([
                'branding' => ['logo_url' => 'https://cdn.example.test/'.$slug.'/logo.png'],
                'app_icon_url' => 'https://cdn.example.test/'.$slug.'/icon.png',
                'primary_color' => '#ff6b00', 'secondary_color' => '#111827',
            ], JSON_THROW_ON_ERROR),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return Tenant::query()->withoutGlobalScopes()->findOrFail($id);
    }

    private function user(Tenant $tenant, string $email): User
    {
        $id = DB::table('users')->insertGetId([
            'tenant_id' => $tenant->id, 'name' => 'Tenant owner', 'email' => $email,
            'password' => 'not-used', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $user = new User;
        $user->forceFill(['id' => $id, 'tenant_id' => $tenant->id, 'name' => 'Tenant owner', 'email' => $email, 'is_active' => true]);
        $user->exists = true;

        return $user;
    }

    private function entitle(Tenant $tenant): void
    {
        $planId = DB::table('subscription_plans')->insertGetId([
            'name' => 'Customer App', 'code' => 'customer-'.$tenant->slug,
            'features' => json_encode(['customer_app', 'customer_app_build', 'customer_app_aab'], JSON_THROW_ON_ERROR),
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('tenant_subscriptions')->insert([
            'tenant_id' => $tenant->id, 'subscription_plan_id' => $planId, 'status' => 'active',
            'starts_at' => now()->subDay(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function registration(Tenant $tenant, string $package): CustomerAppRegistration
    {
        return CustomerAppRegistration::query()->create([
            'tenant_id' => $tenant->id, 'package_id' => $package,
            'display_name' => $tenant->name, 'platform' => 'android', 'status' => 'active',
            'branding_revision' => 1,
        ]);
    }

    private function build(Tenant $tenant, string $status): CustomerAppBuild
    {
        $registration = CustomerAppRegistration::query()->where('tenant_id', $tenant->id)->firstOrFail();

        $snapshot = [
            'schema_version' => 2,
            'tenant' => ['uuid' => $tenant->uuid, 'slug' => $tenant->slug],
            'source' => ['repository' => config('saas.customer_app_build.repository'), 'commit' => str_repeat('a', 40)],
            'application' => [
                'uuid' => $registration->uuid,
                'package_id' => $registration->package_id,
                'platform' => 'android',
            ],
            'build' => ['type' => 'release', 'version' => '1.0.0'],
            'branding' => [
                'display_name' => $tenant->name,
                'logo_url' => 'https://cdn.example.test/'.$tenant->slug.'/logo.png',
                'app_icon_url' => 'https://cdn.example.test/'.$tenant->slug.'/icon.png',
                'splash_logo_url' => null,
                'primary_color' => '#ff6b00',
                'secondary_color' => '#111827',
            ],
            'branding_revision' => 1,
            'api_origin' => config('saas.customer_app.api_origin'),
            'signing' => ['certificate_sha256' => str_repeat('b', 64)],
            'toolchain' => ['flutter' => '3.35.1', 'android_sdk' => '35'],
        ];

        return CustomerAppBuild::query()->create([
            'tenant_id' => $tenant->id, 'customer_app_registration_id' => $registration->id,
            'platform' => 'android', 'build_type' => 'release', 'requested_version' => '1.0.0',
            'status' => $status, 'branding_revision' => 1,
            'build_config_revision' => app(CustomerAppBuildSnapshotValidator::class)->revision($snapshot),
            'request_fingerprint' => str_repeat('b', 64),
            'active_fingerprint' => in_array($status, CustomerAppBuild::ACTIVE_STATUSES, true) ? hash('sha256', $tenant->id.$status) : null,
            'config_snapshot' => $snapshot, 'requested_by' => $this->administratorA->id,
        ]);
    }

    private function workerHeaders(): array
    {
        return ['Authorization' => 'Bearer '.str_repeat('w', 48), 'X-Build-Worker-ID' => 'gha-1'];
    }

    private function createSchema(): void
    {
        Schema::create('translations', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->index();
            $table->json('value');
            $table->timestamps();
        });
        Schema::create('tenants', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('domain')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('settings')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants');
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('password')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();
        });
        Schema::create('model_has_roles', function (Blueprint $table): void {
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->primary(['role_id', 'model_id', 'model_type']);
            $table->index(['model_id', 'model_type']);
        });
        Schema::create('subscription_plans', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->json('features')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('tenant_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants');
            $table->foreignId('subscription_plan_id')->constrained('subscription_plans');
            $table->string('status');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->json('overrides')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        foreach ([1, 3, 4] as $number) {
            $migration = require base_path(sprintf('Modules/Saas/database/migrations/2026_08_11_00000%d_create_customer_app_%s_table.php', $number, $number === 1 ? 'registrations' : ($number === 3 ? 'builds' : 'build_artifacts')));
            $migration->up();
        }
        (require base_path('Modules/Saas/database/migrations/2026_08_12_000001_harden_customer_app_build_worker.php'))->up();
    }

    private function dropSchema(): void
    {
        Schema::disableForeignKeyConstraints();
        foreach (['customer_app_build_artifacts', 'customer_app_builds', 'customer_app_registrations', 'tenant_subscriptions', 'subscription_plans', 'model_has_roles', 'roles', 'users', 'tenants', 'translations'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::enableForeignKeyConstraints();
    }
}
