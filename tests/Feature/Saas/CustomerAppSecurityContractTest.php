<?php

namespace Tests\Feature\Saas;

use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Branch\Models\Branch;
use Modules\Saas\Exceptions\CustomerAppAuthorizationException;
use Modules\Saas\Models\CustomerAppRegistration;
use Modules\Saas\Models\CustomerAppSession;
use Modules\Saas\Models\Tenant;
use Modules\Saas\Services\CustomerApp\CustomerAppAuthorizationService;
use Modules\Saas\Services\CustomerApp\CustomerAppManifestService;
use Modules\Saas\Services\CustomerApp\CustomerAppResourceAuthorizationService;
use Modules\Saas\Support\TenantContext;
use Modules\User\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CustomerAppSecurityContractTest extends TestCase
{
    private CustomerAppAuthorizationService $authorization;
    private CustomerAppManifestService $manifests;
    private CustomerAppResourceAuthorizationService $resources;
    private Tenant $tenantA;
    private Tenant $tenantB;
    private CustomerAppRegistration $appA;
    private Branch $branchA;
    private Branch $branchB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createDisposableSecuritySchema();
        Cache::flush();
        [$private, $public] = $this->rsaKeys();
        config([
            'saas.customer_app.manifest_private_key' => $private,
            'saas.customer_app.manifest_public_key' => $public,
            'saas.customer_app.api_origin' => 'https://api.example.test/v1',
            'saas.customer_app.manifest_ttl_seconds' => 300,
        ]);

        $this->authorization = app(CustomerAppAuthorizationService::class);
        $this->manifests = app(CustomerAppManifestService::class);
        $this->resources = app(CustomerAppResourceAuthorizationService::class);
        $this->tenantA = $this->tenant('tenant-a');
        $this->tenantB = $this->tenant('tenant-b');
        $this->entitle($this->tenantA);
        $this->entitle($this->tenantB);
        $this->branchA = $this->branch($this->tenantA, 'Branch A');
        $this->branchB = $this->branch($this->tenantB, 'Branch B');
        $this->appA = $this->registration($this->tenantA, 'com.nexdine.tenanta');
    }

    protected function tearDown(): void
    {
        $this->dropDisposableSecuritySchema();
        parent::tearDown();
    }

    public function test_app_uuid_resolves_only_its_server_owned_tenant(): void
    {
        $resolved = $this->authorization->resolve(
            $this->appA->uuid, $this->appA->package_id, 'android', $this->branchA->id
        );

        $this->assertSame($this->tenantA->id, $resolved->tenant_id);

        $payload = [
            'app_uuid' => $this->appA->uuid,
            'package_id' => $this->appA->package_id,
            'platform' => $this->appA->platform,
            // Deliberately forged. The bootstrap controller must never use it.
            'tenant_id' => $this->tenantB->id,
        ];
        $this->postJson('/api/v1/saas/customer-app/bootstrap', $payload)
            ->assertOk()
            ->assertJsonPath('body.application.uuid', $this->appA->uuid)
            ->assertJsonMissing(['tenant_id' => $this->tenantB->id]);

        $unknown = $payload;
        $unknown['app_uuid'] = (string) Str::uuid();
        $invalid = $this->postJson('/api/v1/saas/customer-app/bootstrap', $unknown)
            ->assertForbidden()
            ->json();
        $this->assertSame('CUSTOMER_APP_UNAVAILABLE', data_get($invalid, 'body.code'));

        // Named throttle keys are hashed by Laravel's ThrottleRequests middleware.
        RateLimiter::clear(md5('customer-app-bootstrapcustomer-app-bootstrap|127.0.0.1'));
        for ($attempt = 1; $attempt <= 20; $attempt++) {
            $this->postJson('/api/v1/saas/customer-app/bootstrap', $payload)->assertOk();
        }
        $throttled = $this->postJson('/api/v1/saas/customer-app/bootstrap', $unknown)
            ->assertTooManyRequests()
            ->json();
        $this->assertSame('CUSTOMER_APP_THROTTLED', data_get($throttled, 'body.code'));
    }

    public function test_inactive_registration_is_rejected(): void
    {
        $this->appA->update(['status' => CustomerAppRegistration::STATUS_REVOKED]);
        $this->expectSecurityCode('APP_INACTIVE', fn () => $this->resolveA());
    }

    public function test_inactive_tenant_is_rejected(): void
    {
        DB::table('tenants')->where('id', $this->tenantA->id)->update(['is_active' => false]);
        $this->tenantA->refresh();
        $this->expectSecurityCode('TENANT_SUSPENDED', fn () => $this->resolveA());
    }

    public function test_package_platform_and_forged_tenant_are_rejected(): void
    {
        $this->expectSecurityCode('PACKAGE_MISMATCH', fn () => $this->authorization->resolve($this->appA->uuid, 'com.attacker.app', 'android'));
        $this->expectSecurityCode('PLATFORM_MISMATCH', fn () => $this->authorization->resolve($this->appA->uuid, $this->appA->package_id, 'ios'));
        $this->expectSecurityCode('TENANT_MISMATCH', fn () => $this->authorization->resolve($this->appA->uuid, $this->appA->package_id, 'android', clientTenantId: $this->tenantB->id));
    }

    public function test_foreign_customer_token_and_branch_are_rejected(): void
    {
        $foreignCustomer = new User();
        $foreignCustomer->forceFill(['tenant_id' => $this->tenantB->id]);
        $this->expectSecurityCode('TENANT_MISMATCH', fn () => $this->authorization->resolve(
            $this->appA->uuid, $this->appA->package_id, 'android', customer: $foreignCustomer
        ));
        $this->expectSecurityCode('BRANCH_FORBIDDEN', fn () => $this->authorization->resolve(
            $this->appA->uuid, $this->appA->package_id, 'android', branchId: $this->branchB->id
        ));
    }

    #[DataProvider('tenantOwnedResourceProvider')]
    public function test_foreign_customer_resources_are_rejected(string $resourceType): void
    {
        $this->resources->assertOwned($this->appA, $this->tenantA->id, $resourceType);
        $this->addToAssertionCount(1);

        $this->expectSecurityCode('RESOURCE_FORBIDDEN', fn () => $this->resources->assertOwned(
            $this->appA,
            $this->tenantB->id,
            $resourceType,
        ));
    }

    public static function tenantOwnedResourceProvider(): array
    {
        return [
            'cart' => ['cart'],
            'product' => ['product'],
            'menu' => ['menu'],
            'address' => ['address'],
            'order' => ['order'],
            'reservation' => ['reservation'],
            'device token' => ['device token'],
        ];
    }

    public function test_missing_or_expired_subscription_and_missing_explicit_feature_are_rejected(): void
    {
        DB::table('tenant_subscriptions')->where('tenant_id', $this->tenantA->id)->delete();
        $this->expectSecurityCode('SUBSCRIPTION_INACTIVE', fn () => $this->resolveA());

        $this->entitle($this->tenantA, [], CarbonImmutable::now()->subMinute());
        $this->expectSecurityCode('SUBSCRIPTION_INACTIVE', fn () => $this->resolveA());

        $this->entitle($this->tenantA, []);
        $this->expectSecurityCode('PLAN_FEATURE_DISABLED', fn () => $this->resolveA());
    }

    public function test_signed_manifest_verifies_and_tampering_fails(): void
    {
        $now = CarbonImmutable::parse('2026-08-11T10:00:00Z');
        $envelope = $this->manifests->issue($this->appA, $now);
        $this->assertSame($this->appA->id, $this->manifests->verify(
            $envelope,
            $now->addMinute(),
            $this->appA->uuid,
            $this->appA->package_id,
            $this->appA->platform,
        )->id);

        $identityEnvelope = $this->manifests->issue($this->appA, $now);
        $this->expectSecurityCode('MANIFEST_IDENTITY_MISMATCH', fn () => $this->manifests->verify(
            $identityEnvelope,
            $now->addMinute(),
            (string) Str::uuid(),
            $this->appA->package_id,
            $this->appA->platform,
        ));

        $envelope['manifest']['package_id'] = 'com.attacker.app';
        $this->expectSecurityCode('MANIFEST_SIGNATURE_INVALID', fn () => $this->manifests->verify($envelope, $now->addMinute()));
    }

    public function test_expired_and_replayed_stale_manifest_are_rejected(): void
    {
        $now = CarbonImmutable::parse('2026-08-11T10:00:00Z');
        $expired = $this->manifests->issue($this->appA, $now);
        $this->expectSecurityCode('MANIFEST_EXPIRED', fn () => $this->manifests->verify($expired, $now->addMinutes(6)));

        $future = $this->manifests->issue($this->appA, $now->addMinutes(2));
        $this->expectSecurityCode('MANIFEST_EXPIRED', fn () => $this->manifests->verify($future, $now));

        $current = $this->manifests->issue($this->appA, $now);
        $this->manifests->verify($current, $now->addMinute());
        $this->expectSecurityCode('MANIFEST_REPLAYED', fn () => $this->manifests->verify($current, $now->addMinute()));

        $current = $this->manifests->issue($this->appA, $now);
        $this->appA->increment('branding_revision');
        $this->expectSecurityCode('MANIFEST_STALE', fn () => $this->manifests->verify($current, $now->addMinute()));
    }

    public function test_manifest_rechecks_current_tenant_and_subscription_state(): void
    {
        $now = CarbonImmutable::parse('2026-08-11T10:00:00Z');
        $envelope = $this->manifests->issue($this->appA, $now);

        DB::table('tenants')->where('id', $this->tenantA->id)->update(['is_active' => false]);
        $this->tenantA->refresh();
        $this->expectSecurityCode('TENANT_SUSPENDED', fn () => $this->manifests->verify($envelope, $now->addMinute()));

        DB::table('tenants')->where('id', $this->tenantA->id)->update(['is_active' => true]);
        $this->tenantA->refresh();
        DB::table('tenant_subscriptions')->where('tenant_id', $this->tenantA->id)->delete();
        $this->expectSecurityCode('SUBSCRIPTION_INACTIVE', fn () => $this->manifests->verify($envelope, $now->addMinute()));
    }

    public function test_duplicate_registration_and_package_collision_are_database_enforced(): void
    {
        try {
            $this->registration($this->tenantA, 'com.nexdine.second');
            $this->fail('Tenant/platform uniqueness was not enforced.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }

        try {
            $this->registration($this->tenantB, $this->appA->package_id);
            $this->fail('Package uniqueness was not enforced.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }

        $columns = collect(Schema::getColumns('customer_app_registrations'))->keyBy('name');
        $this->assertFalse((bool) $columns->get('uuid')['nullable']);
        $this->assertFalse((bool) $columns->get('tenant_id')['nullable']);
        $indexes = collect(Schema::getIndexes('customer_app_registrations'))->pluck('name');
        $this->assertContains('customer_app_tenant_platform_unique', $indexes);
        $this->assertContains('customer_app_tenant_status_idx', $indexes);
        $this->assertNotEmpty(Schema::getForeignKeys('customer_app_registrations'));

        $registrationMigration = require base_path('Modules/Saas/database/migrations/2026_08_11_000001_create_customer_app_registrations_table.php');
        $sessionMigration = require base_path('Modules/Saas/database/migrations/2026_08_11_000002_create_customer_app_sessions_table.php');
        $sessionMigration->down();
        $registrationMigration->down();
        $this->assertFalse(Schema::hasTable('customer_app_registrations'));
        $registrationMigration->up();
        $sessionMigration->up();
        $this->assertTrue(Schema::hasTable('customer_app_registrations'));
    }

    public function test_http_session_exchange_is_one_time_and_identity_bound(): void
    {
        $installationId = (string) Str::uuid();
        $manifest = $this->manifests->issue($this->appA);
        $payload = [
            'app_uuid' => $this->appA->uuid,
            'package_id' => $this->appA->package_id,
            'platform' => $this->appA->platform,
            'installation_id' => $installationId,
            'manifest' => $manifest,
        ];

        $response = $this->postJson('/api/v1/saas/customer-app/session', $payload)
            ->assertOk()
            ->assertJsonStructure(['body' => ['session_token', 'expires_at']]);

        $this->assertDatabaseHas('customer_app_sessions', [
            'tenant_id' => $this->tenantA->id,
            'customer_app_registration_id' => $this->appA->id,
            'installation_id' => $installationId,
            'token_hash' => hash('sha256', $response->json('body.session_token')),
        ]);

        $this->postJson('/api/v1/saas/customer-app/session', $payload)
            ->assertForbidden()
            ->assertJsonPath('body.code', 'CUSTOMER_APP_UNAVAILABLE');

        $wrongPackage = $payload;
        $wrongPackage['manifest'] = $this->manifests->issue($this->appA);
        $wrongPackage['package_id'] = 'com.attacker.customer';
        $this->postJson('/api/v1/saas/customer-app/session', $wrongPackage)
            ->assertForbidden()
            ->assertJsonPath('body.code', 'CUSTOMER_APP_UNAVAILABLE');
    }

    public function test_actual_customer_auth_route_requires_a_live_server_derived_app_session(): void
    {
        $this->postJson('/api/v1/customer-auth/login', [
            'tenant_id' => $this->tenantB->id,
            'customer_id' => 999999,
        ])->assertUnauthorized()
            ->assertJsonPath('body.code', 'CUSTOMER_APP_UNAVAILABLE');

        $token = $this->exchangeSessionToken();
        $this->postJson('/api/v1/customer-auth/login', [
            'tenant_id' => $this->tenantB->id,
            'customer_id' => 999999,
            'branch_id' => 999999,
            'restaurant_id' => $this->tenantB->id,
            'app_id' => (string) Str::uuid(),
        ], [
            'X-NexDine-Customer-App-Token' => $token,
            'X-Tenant-ID' => (string) $this->tenantB->id,
            'X-Tenant-Id' => (string) $this->tenantB->id,
        ])
            ->assertForbidden()
            ->assertJsonPath('body.code', 'CUSTOMER_APP_UNAVAILABLE');

        $session = CustomerAppSession::query()->firstOrFail();
        $session->forceFill(['expires_at' => now()->subSecond()])->save();
        $this->postJson('/api/v1/customer-auth/login', [], [
            'X-NexDine-Customer-App-Token' => $token,
        ])->assertUnauthorized()
            ->assertJsonPath('body.code', 'CUSTOMER_APP_UNAVAILABLE');
    }

    #[DataProvider('protectedCustomerRouteProvider')]
    public function test_every_registered_customer_app_surface_fails_closed_without_a_session(
        string $method,
        string $uri,
    ): void {
        $response = $this->json($method, $uri)->assertUnauthorized();
        $this->assertContains($response->json('body.code'), [
            // Anonymous catalog/bootstrap routes fail at app-session
            // resolution; customer-owned routes authenticate first so the
            // request cannot use an app token as a substitute for identity.
            'CUSTOMER_APP_UNAVAILABLE',
            'CUSTOMER_AUTH_REQUIRED',
        ]);
    }

    public static function protectedCustomerRouteProvider(): array
    {
        $cart = '00000000-0000-4000-8000-000000000001';
        $resource = 'RSV-ABCDEFGHIJ';

        return [
            'menu' => ['GET', '/api/v1/customer-app/online-menus/example/menu'],
            'cart show' => ['GET', "/api/v1/customer-app/cart/{$cart}"],
            'cart meta' => ['GET', "/api/v1/customer-app/cart/{$cart}/meta"],
            'cart clear' => ['DELETE', "/api/v1/customer-app/cart/{$cart}/clear"],
            'cart initialize' => ['POST', "/api/v1/customer-app/cart/{$cart}/initialize"],
            'cart item' => ['POST', "/api/v1/customer-app/cart/{$cart}/items"],
            'cart item batch' => ['POST', "/api/v1/customer-app/cart/{$cart}/items/batch"],
            'cart item update' => ['PUT', "/api/v1/customer-app/cart/{$cart}/items/item-1"],
            'cart item delete' => ['DELETE', "/api/v1/customer-app/cart/{$cart}/items/item-1"],
            'cart item action' => ['POST', "/api/v1/customer-app/cart/{$cart}/items/item-1/action"],
            'cart item action delete' => ['DELETE', "/api/v1/customer-app/cart/{$cart}/items/item-1/action"],
            'order tracking' => ['GET', '/api/v1/customer-app/orders/tracking/foreign-reference'],
            'order create' => ['POST', "/api/v1/customer-app/orders/{$cart}"],
            'feedback' => ['POST', '/api/v1/customer-app/orders/feedback'],
            'QR resolution' => ['POST', '/api/v1/customer-app/qr-order/resolve'],
            'reservation list' => ['GET', '/api/v1/customer-app/reservations'],
            'reservation create' => ['POST', '/api/v1/customer-app/reservations'],
            'reservation show' => ['GET', "/api/v1/customer-app/reservations/{$resource}"],
            'reservation update' => ['PUT', "/api/v1/customer-app/reservations/{$resource}"],
            'reservation cancel' => ['POST', "/api/v1/customer-app/reservations/{$resource}/cancel"],
            'customer register' => ['POST', '/api/v1/customer-auth/register'],
            'customer login' => ['POST', '/api/v1/customer-auth/login'],
        ];
    }

    public function test_registered_customer_app_routes_have_canonical_context_and_security_boundaries(): void
    {
        $customerRoutes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'api/v1/customer-app/'));

        $this->assertCount(57, $customerRoutes, 'The certified route inventory changed; review every new surface.');

        foreach ($customerRoutes as $route) {
            $middleware = $route->gatherMiddleware();
            $this->assertContains(
                \Modules\Saas\Http\Middleware\ResolveCustomerAppContext::class,
                $middleware,
                "{$route->methods()[0]} {$route->uri()} is missing canonical Customer App context.",
            );
            $this->assertTrue(
                collect($middleware)->contains(fn ($entry) => str_contains((string) $entry, 'ThrottleRequests')
                    || str_starts_with((string) $entry, 'throttle:')),
                "{$route->methods()[0]} {$route->uri()} is missing rate limiting.",
            );
        }

        $authRoutes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'api/v1/customer-auth/'));
        $this->assertCount(17, $authRoutes, 'The certified customer identity route inventory changed.');

        foreach ($authRoutes as $route) {
            $browserOtpRoutes = [
                'api/v1/customer-auth/otp/request',
                'api/v1/customer-auth/otp/verify',
            ];
            if (! in_array($route->uri(), $browserOtpRoutes, true)) {
                $this->assertContains(
                    \Modules\Saas\Http\Middleware\ResolveCustomerAppContext::class,
                    $route->gatherMiddleware(),
                    "{$route->methods()[0]} {$route->uri()} is missing canonical Customer App context.",
                );
            }

            $preAuthenticationRoutes = [
                'api/v1/customer-auth/apple',
                'api/v1/customer-auth/google',
                'api/v1/customer-auth/login',
                'api/v1/customer-auth/register',
                'api/v1/customer-auth/otp/request',
                'api/v1/customer-auth/otp/verify',
            ];
            if (! in_array($route->uri(), $preAuthenticationRoutes, true)) {
                $this->assertTrue(
                    collect($route->gatherMiddleware())->contains(fn ($entry) => str_contains((string) $entry, 'Authenticate:sanctum')
                        || (string) $entry === 'auth:sanctum'),
                    "{$route->methods()[0]} {$route->uri()} must require customer authentication.",
                );
            }
        }
    }

    public function test_group_order_routes_require_customer_auth_and_idempotent_mutations(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'api/v1/customer-app/group-orders'));

        $this->assertCount(15, $routes);
        foreach ($routes as $route) {
            $middleware = collect($route->gatherMiddleware())->map(fn ($entry) => (string) $entry);
            $this->assertTrue(
                $middleware->contains(fn ($entry) => $entry === 'auth:sanctum' || str_contains($entry, 'Authenticate:sanctum')),
                "{$route->methods()[0]} {$route->uri()} must require a verified customer.",
            );
            $this->assertTrue(
                $middleware->contains(fn ($entry) => str_contains($entry, 'ResolveCustomerAppContext')),
                "{$route->methods()[0]} {$route->uri()} must resolve the signed application tenant.",
            );
            if (! in_array('GET', $route->methods(), true)
                && ! str_ends_with($route->uri(), '/broadcasting/auth')) {
                $this->assertTrue(
                    $middleware->contains(fn ($entry) => str_contains($entry, 'EnsureIdempotentRequest')),
                    "{$route->methods()[0]} {$route->uri()} must reject duplicate mutations.",
                );
            }
        }
    }

    public function test_legacy_public_routes_remain_explicitly_tenant_scoped_and_rate_limited(): void
    {
        $prefixes = [
            'api/v1/public/cart/',
            'api/v1/online-menus/',
            'api/v1/public/orders/',
            'api/v1/qr-order/',
            'api/v1/customer-reservations',
        ];

        $routes = collect(Route::getRoutes()->getRoutes())->filter(function ($route) use ($prefixes): bool {
            return collect($prefixes)->contains(fn ($prefix) => str_starts_with($route->uri(), $prefix));
        });

        $this->assertNotEmpty($routes);
        foreach ($routes as $route) {
            $middleware = $route->gatherMiddleware();
            $this->assertContains(
                \Modules\Saas\Http\Middleware\EnsureAuthenticatedTenant::class,
                $middleware,
                "{$route->methods()[0]} {$route->uri()} is not explicitly tenant scoped.",
            );
            $this->assertTrue(
                collect($middleware)->contains(fn ($entry) => str_contains((string) $entry, 'ThrottleRequests')
                    || str_starts_with((string) $entry, 'throttle:')),
                "{$route->methods()[0]} {$route->uri()} is missing rate limiting.",
            );
        }
    }

    #[DataProvider('spoofedIdentityProvider')]
    public function test_actual_http_route_rejects_every_spoofable_identity_alias(
        array $payload,
        array $headers = [],
    ): void {
        $headers['X-NexDine-Customer-App-Token'] = $this->exchangeSessionToken();

        $this->postJson('/api/v1/customer-auth/login', $payload, $headers)
            ->assertForbidden()
            ->assertJsonPath('body.code', 'CUSTOMER_APP_UNAVAILABLE');
    }

    public static function spoofedIdentityProvider(): array
    {
        return [
            'tenant_id' => [['tenant_id' => 999999]],
            'tenantId' => [['tenantId' => 999999]],
            'restaurant_id' => [['restaurant_id' => 999999]],
            'restaurantId' => [['restaurantId' => 999999]],
            'branch_id' => [['branch_id' => 999999]],
            'branchId' => [['branchId' => 999999]],
            'customer_id' => [['customer_id' => 999999]],
            'customerId' => [['customerId' => 999999]],
            'app_id' => [['app_id' => '00000000-0000-4000-8000-000000000099']],
            'appId' => [['appId' => '00000000-0000-4000-8000-000000000099']],
            'X-Tenant-ID' => [[], ['X-Tenant-ID' => '999999']],
            'X-Restaurant-ID' => [[], ['X-Restaurant-ID' => '999999']],
            'X-Branch-ID' => [[], ['X-Branch-ID' => '999999']],
            'X-NexDine-Tenant-Domain' => [[], ['X-NexDine-Tenant-Domain' => 'foreign.example.test']],
        ];
    }

    public function test_authoritative_identity_is_allowed_and_request_context_is_always_cleared(): void
    {
        $token = $this->exchangeSessionToken();

        $this->postJson('/api/v1/customer-auth/login', [
            'tenant_id' => $this->tenantA->id,
            'app_id' => $this->appA->uuid,
            'branch_id' => $this->branchA->id,
        ], ['X-NexDine-Customer-App-Token' => $token])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['menu_slug', 'login', 'password']);

        $this->assertNull(app(TenantContext::class)->get());
        $this->assertFalse(app()->bound(\Modules\Saas\Support\CustomerAppContext::class));
    }

    public function test_http_app_session_rechecks_registration_and_tenant_state(): void
    {
        $token = $this->exchangeSessionToken();
        $this->appA->update(['status' => CustomerAppRegistration::STATUS_REVOKED]);

        $this->postJson('/api/v1/customer-auth/login', [], [
            'X-NexDine-Customer-App-Token' => $token,
        ])->assertForbidden()
            ->assertJsonPath('body.code', 'CUSTOMER_APP_UNAVAILABLE');
    }

    private function resolveA(): CustomerAppRegistration
    {
        return $this->authorization->resolve($this->appA->uuid, $this->appA->package_id, 'android');
    }

    private function tenant(string $slug): Tenant
    {
        $id = DB::table('tenants')->insertGetId([
            'name' => $slug,
            'slug' => $slug,
            'domain' => $slug.'.example.test',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Tenant::query()->withoutGlobalScopes()->findOrFail($id);
    }

    private function entitle(Tenant $tenant, array $features = ['customer_app'], ?CarbonImmutable $endsAt = null): void
    {
        $planId = DB::table('subscription_plans')->insertGetId([
            'name' => 'Customer '.$tenant->slug.uniqid(),
            'code' => 'customer-'.$tenant->slug.'-'.uniqid(),
            'features' => json_encode($features, JSON_THROW_ON_ERROR),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('tenant_subscriptions')->insert([
            'tenant_id' => $tenant->id,
            'subscription_plan_id' => $planId,
            'status' => 'active',
            'starts_at' => now()->subDay(),
            'ends_at' => $endsAt,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function branch(Tenant $tenant, string $name): Branch
    {
        $id = DB::table('branches')->insertGetId([
            'tenant_id' => $tenant->id,
            'name' => $name,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Branch::query()->withoutGlobalScopes()->findOrFail($id);
    }

    private function registration(Tenant $tenant, string $package): CustomerAppRegistration
    {
        $id = DB::table('customer_app_registrations')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'package_id' => $package,
            'display_name' => $tenant->name,
            'platform' => 'android',
            'status' => 'active',
            'branding_revision' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return CustomerAppRegistration::query()->findOrFail($id);
    }

    private function createDisposableSecuritySchema(): void
    {
        $database = (string) DB::connection()->getDatabaseName();
        $this->assertTrue(
            $database === ':memory:' || str_ends_with($database, '_test'),
            "Refusing to rebuild disposable security schema in non-test database [{$database}].",
        );
        // Recover safely when a prior interrupted local test left a partial
        // disposable schema behind. This is deliberately guarded above.
        $this->dropDisposableSecuritySchema();

        Schema::create('tenants', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('domain')->nullable();
            $table->json('settings')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
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
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('subscription_plan_id')->constrained('subscription_plans');
            $table->string('status');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->json('overrides')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('branches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('translations', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->index();
            $table->json('value');
            $table->timestamps();
        });
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('name')->nullable();
            $table->timestamps();
        });

        $migration = require base_path('Modules/Saas/database/migrations/2026_08_11_000001_create_customer_app_registrations_table.php');
        $migration->up();
        $sessionMigration = require base_path('Modules/Saas/database/migrations/2026_08_11_000002_create_customer_app_sessions_table.php');
        $sessionMigration->up();
        $contentMigration = require base_path('Modules/Saas/database/migrations/2026_08_12_000002_create_customer_app_content_items_table.php');
        $contentMigration->up();
        $settingsMigration = require base_path('Modules/Saas/database/migrations/2026_08_12_000003_create_customer_app_settings_table.php');
        $settingsMigration->up();

        $this->assertTrue(Schema::hasColumns('customer_app_registrations', [
            'uuid', 'tenant_id', 'package_id', 'platform', 'status', 'branding_revision',
        ]));
    }

    private function dropDisposableSecuritySchema(): void
    {
        Schema::disableForeignKeyConstraints();
        foreach (['customer_app_build_artifacts', 'customer_app_builds', 'customer_app_settings', 'customer_app_content_items', 'customer_app_sessions', 'customer_app_registrations', 'users', 'translations', 'branches', 'tenant_subscriptions', 'subscription_plans', 'tenants'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::enableForeignKeyConstraints();
    }

    private function expectSecurityCode(string $code, callable $operation): void
    {
        try {
            $operation();
            $this->fail("Expected security error {$code}.");
        } catch (CustomerAppAuthorizationException $exception) {
            $this->assertSame($code, $exception->machineCode);
        }
    }

    private function exchangeSessionToken(): string
    {
        return (string) $this->postJson('/api/v1/saas/customer-app/session', [
            'app_uuid' => $this->appA->uuid,
            'package_id' => $this->appA->package_id,
            'platform' => $this->appA->platform,
            'installation_id' => (string) Str::uuid(),
            'manifest' => $this->manifests->issue($this->appA),
        ])->assertOk()->json('body.session_token');
    }

    private function rsaKeys(): array
    {
        $resource = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($resource, $private);
        $public = openssl_pkey_get_details($resource)['key'];
        return [$private, $public];
    }
}
