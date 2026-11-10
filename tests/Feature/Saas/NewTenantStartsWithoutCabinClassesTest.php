<?php

namespace Tests\Feature\Saas;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Modules\Saas\Services\Provisioning\SaasProvisioningService;
use Tests\TestCase;

/**
 * A new agency adds its own cabin classes; none are copied in for it.
 */
class NewTenantStartsWithoutCabinClassesTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_agency_has_no_cabin_classes(): void
    {
        Queue::fake();
        Http::preventStrayRequests();

        // A platform template exists that used to be copied into every tenant.
        DB::table('flight_cabin_classes')->insert([
            'tenant_id' => null, 'code' => 'ECONOMY', 'name' => 'Economy', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $tenantId = (int) app(SaasProvisioningService::class)->provision([
            'name' => 'Blank Agency',
            'slug' => 'blank-agency-' . uniqid(),
            'email' => 'owner+' . uniqid() . '@example.test',
            'phone' => '9000000002',
            'admin_name' => 'Blank Owner',
            'password' => 'Secret#12345',
            'plan' => 'starter',
        ])['tenant']->id;

        $this->assertSame(0, DB::table('flight_cabin_classes')->where('tenant_id', $tenantId)->count());
    }
}
