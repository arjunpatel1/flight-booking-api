<?php

namespace Tests\Feature\FinancialDashboard;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Modules\FinancialDashboard\Services\FinancialDashboardService;
use Modules\Order\Enums\OrderPaymentStatus;
use Modules\Order\Enums\OrderStatus;
use Modules\Report\Models\FactSalesDaily;
use Modules\Saas\Models\Tenant;
use Tests\Support\AggregatorTestSupport;
use Tests\TestCase;

class FinancialDashboardTenantParityTest extends TestCase
{
    use AggregatorTestSupport;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAggregatorTestSupport();
        Cache::flush();
    }

    public function test_kpis_match_normal_dashboard_non_cancelled_order_definition(): void
    {
        $tenant = $this->tenant('parity');
        $branch = $this->makeBranch(['tenant_id' => $tenant->id]);
        $actor = $this->actingAsUserWithPermissions(['admin.financial_dashboard.index']);
        $actor->forceFill(['tenant_id' => $tenant->id, 'branch_id' => $branch->id])->save();

        $included = $this->makeOrder($branch, [
            'status' => OrderStatus::Completed,
            'payment_status' => OrderPaymentStatus::Paid,
            'total' => 999.35,
            'subtotal' => 999.35,
        ]);
        $included->forceFill(['created_at' => now()])->save();
        $unpaid = $this->makeOrder($branch, [
            'status' => OrderStatus::Completed,
            'payment_status' => OrderPaymentStatus::Unpaid,
            'total' => 500,
            'subtotal' => 500,
        ]);
        $unpaid->forceFill(['created_at' => now()])->save();
        $active = $this->makeOrder($branch, [
            'status' => OrderStatus::Pending,
            'payment_status' => OrderPaymentStatus::Paid,
            'total' => 250,
            'subtotal' => 250,
        ]);
        $active->forceFill(['created_at' => now()])->save();

        $today = today()->toDateString();
        $kpis = app(FinancialDashboardService::class)->getKpis($today, $today, $branch->id);

        $this->assertSame(3, $kpis['today']['orders']);
        $this->assertEqualsWithDelta(1749.35, $kpis['today']['net_sales'], 0.001);
        $this->assertEqualsWithDelta(583.1167, $kpis['today']['avg_order_value'], 0.001);
    }

    public function test_branch_performance_never_uses_another_tenants_cached_facts(): void
    {
        $tenantA = $this->tenant('tenant-a');
        $tenantB = $this->tenant('tenant-b');
        $branchA = $this->makeBranch(['tenant_id' => $tenantA->id, 'name' => ['en' => 'Tenant A Outlet']]);
        $branchB = $this->makeBranch(['tenant_id' => $tenantB->id, 'name' => ['en' => 'Tenant B Outlet']]);
        $this->fact($branchA->id, 100);
        $this->fact($branchB->id, 900);

        $actorA = $this->actingAsUserWithPermissions(['admin.financial_dashboard.index']);
        $actorA->forceFill(['tenant_id' => $tenantA->id, 'branch_id' => null])->save();
        $actorA->refresh();

        $rowsA = app(FinancialDashboardService::class)->getBranchAnalytics(today()->toDateString(), today()->toDateString());

        $this->assertSame([$branchA->id], collect($rowsA)->pluck('branch_id')->all());

        $actorB = $this->actingAsUserWithPermissions(['admin.financial_dashboard.index']);
        $actorB->forceFill(['tenant_id' => $tenantB->id, 'branch_id' => null])->save();
        $actorB->refresh();

        $rowsB = app(FinancialDashboardService::class)->getBranchAnalytics(today()->toDateString(), today()->toDateString());

        $this->assertSame([$branchB->id], collect($rowsB)->pluck('branch_id')->all());
    }

    private function tenant(string $slug): Tenant
    {
        return Tenant::query()->withoutGlobalScopes()->create([
            'name' => ucfirst($slug), 'slug' => $slug,
            'domain' => $slug.'.example.test', 'is_active' => true,
        ]);
    }

    private function fact(int $branchId, float $sales): void
    {
        DB::table('fact_sales_dailies')->insert([
            'branch_id' => $branchId, 'business_date' => today()->toDateString(), 'currency' => 'INR',
            'total_orders' => 1, 'gross_sales' => $sales, 'net_sales' => $sales,
            'average_order_value' => $sales, 'profit_total' => $sales,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
