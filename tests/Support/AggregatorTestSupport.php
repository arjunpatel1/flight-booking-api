<?php

namespace Tests\Support;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Aggregator\Enums\AggregatorProvider;
use Modules\Aggregator\Models\AggregatorIntegration;
use Modules\Aggregator\Models\AggregatorMenuMapping;
use Modules\Aggregator\Models\AggregatorOutletMapping;
use Modules\Branch\Models\Branch;
use Modules\Menu\Models\Menu;
use Modules\Order\Enums\OrderPaymentStatus;
use Modules\Order\Enums\OrderStatus;
use Modules\Order\Enums\OrderType;
use Modules\Order\Models\Order;
use Modules\User\Models\Permission;
use Modules\User\Models\User;
use Spatie\Permission\PermissionRegistrar;

trait AggregatorTestSupport
{
    protected function setUpAggregatorTestSupport(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Artisan::call('permission:sync-permissions');
    }

    protected function actingAsUserWithPermissions(array $permissions): User
    {
        $user = User::factory()->create([
            'is_active' => true,
        ]);

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'api');
        }

        $user->givePermissionTo($permissions);
        Sanctum::actingAs($user, ['*'], 'api');

        return $user;
    }

    protected function aggregatorPermission(string $action): string
    {
        return "admin.aggregator_integrations.{$action}";
    }

    protected function makeIntegration(array $overrides = []): AggregatorIntegration
    {
        return AggregatorIntegration::query()->create([
            'provider' => AggregatorProvider::Swiggy,
            'name' => 'Swiggy Test',
            'base_url' => 'https://partner.example.test',
            'credentials' => [
                'client_id' => 'client-test',
                'secret' => 'secret-test',
            ],
            'webhook_secret' => 'webhook-secret',
            'settings' => [
                'auto_sync' => true,
                'auto_menu_sync' => true,
                'auto_status_sync' => true,
                'webhook_processing' => true,
                'retry_enabled' => true,
                'contract_status' => 'official_contract_verified',
                'official_contract_verified' => true,
            ],
            'is_active' => true,
            ...$overrides,
        ]);
    }

    protected function makeBranch(array $overrides = []): Branch
    {
        return Branch::factory()->create($overrides);
    }

    protected function makeMenu(?Branch $branch = null, array $overrides = []): Menu
    {
        $branch ??= $this->makeBranch();

        return Menu::factory()->create([
            'branch_id' => $branch->id,
            'is_active' => true,
            ...$overrides,
        ]);
    }

    protected function mapOutlet(AggregatorIntegration $integration, Branch $branch, array $overrides = []): AggregatorOutletMapping
    {
        return AggregatorOutletMapping::query()->create([
            'aggregator_integration_id' => $integration->id,
            'branch_id' => $branch->id,
            'external_outlet_id' => 'OUTLET-'.$branch->id,
            'external_outlet_name' => 'Outlet '.$branch->id,
            'is_active' => true,
            ...$overrides,
        ]);
    }

    protected function mapMenu(AggregatorIntegration $integration, Menu $menu, array $overrides = []): AggregatorMenuMapping
    {
        return AggregatorMenuMapping::query()->create([
            'aggregator_integration_id' => $integration->id,
            'menu_id' => $menu->id,
            'external_menu_id' => 'MENU-'.$menu->id,
            'sync_enabled' => true,
            ...$overrides,
        ]);
    }

    protected function makeOrder(Branch $branch, array $overrides = []): Order
    {
        $forceFill = array_intersect_key($overrides, array_flip(['created_at', 'updated_at', 'deleted_at']));
        $overrides = array_diff_key($overrides, $forceFill);

        $order = Order::query()->create([
            'branch_id' => $branch->id,
            'reference_no' => 'TEST-'.Str::upper(Str::random(10)),
            'order_number' => 'TEST-'.Str::upper(Str::random(6)),
            'status' => OrderStatus::Pending,
            'type' => OrderType::Takeaway,
            'payment_status' => OrderPaymentStatus::Unpaid,
            'currency' => 'INR',
            'currency_rate' => 1,
            'subtotal' => 100,
            'total' => 100,
            'guest_count' => 1,
            'order_date' => today(),
            ...$overrides,
        ]);

        if ($forceFill) {
            $order->forceFill($forceFill)->save();
        }

        return $order->refresh();
    }

    protected function webhookPayload(array $overrides = []): array
    {
        return [
            'event_type' => 'order.created',
            'event_id' => 'evt_'.Str::random(10),
            'data' => [
                'external_order_id' => 'agg_order_1001',
                'status' => 'created',
            ],
            ...$overrides,
        ];
    }

    protected function nexdineSignature(array $payload, string $secret = 'webhook-secret'): string
    {
        return hash_hmac('sha256', json_encode($payload), $secret);
    }
}
