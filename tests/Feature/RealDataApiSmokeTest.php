<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Laravel\Sanctum\Sanctum;
use Modules\Branch\Models\Branch;
use Modules\Category\Models\Category;
use Modules\Inventory\Models\Unit;
use Modules\Menu\Models\Menu;
use Modules\Notification\Events\NotificationCreated;
use Modules\Notification\Models\Notification;
use Modules\Order\Enums\OrderPaymentStatus;
use Modules\Order\Enums\OrderProductStatus;
use Modules\Order\Enums\ReasonType;
use Modules\Order\Enums\OrderStatus;
use Modules\Order\Enums\OrderType;
use Modules\Order\Models\Order;
use Modules\Order\Models\Reason;
use Modules\Payment\Enums\PaymentMethod;
use Modules\Pos\Enums\PosSessionStatus;
use Modules\Pos\Models\PosRegister;
use Modules\Pos\Models\PosSession;
use Modules\Pos\Models\PosManagerApproval;
use Modules\Pos\Models\PosOfflineOrder;
use Modules\Pos\Models\PosTerminalDevice;
use Modules\Printer\Enum\PrintContentType;
use Modules\Printer\Enum\PrintJobStatus;
use Modules\Printer\Enum\PrinterConnectionType;
use Modules\Printer\Enum\PrinterPaperSize;
use Modules\Printer\Models\PrintAgent;
use Modules\Printer\Models\PrintJob;
use Modules\Printer\Models\Printer;
use Modules\Printer\Models\PrinterAssignment;
use Modules\Printer\Jobs\DispatchPrintJob;
use Modules\Printer\Services\Dispatcher\PrintDispatcherServiceInterface;
use Modules\Printer\Services\AgentPoll\AgentPollServiceInterface;
use Modules\Printer\Services\PrintJob\PrintJobServiceInterface;
use Modules\Printer\Services\Render\PrintRenderServiceInterface;
use Modules\SeatingPlan\Enums\TableStatus;
use Modules\SeatingPlan\Models\Floor;
use Modules\SeatingPlan\Models\Table;
use Modules\SeatingPlan\Models\Zone;
use Modules\Saas\Models\Tenant;
use Modules\Saas\Models\SubscriptionPlan;
use Modules\Saas\Models\TenantSubscription;
use Modules\Tax\Enums\TaxType;
use Modules\Tax\Models\Tax;
use Modules\Tax\Services\TaxCalculationService;
use Modules\User\Enums\DefaultRole;
use Modules\User\Models\Permission;
use Modules\User\Models\User;
use Modules\User\Services\Mfa\TotpService;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\TestCase;

#[Group('real-db-smoke')]
class RealDataApiSmokeTest extends TestCase
{
    protected Branch $branch;

    protected User $user;

    protected bool $transactionStarted = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (env('REAL_DB_SMOKE') !== '1') {
            $this->markTestSkipped('Set REAL_DB_SMOKE=1 to run real database API smoke tests.');
        }

        $this->useRealDatabaseConnection();

        DB::beginTransaction();
        $this->transactionStarted = true;

        Artisan::call('permission:sync-permissions');

        $this->branch = Branch::factory()->create();
        $this->user = User::factory()->create([
            'branch_id' => $this->branch->id,
            'is_active' => true,
        ]);

        $permissions = [
            'admin.imports.index',
            'admin.imports.show',
            'admin.imports.import',
            'admin.customers.index',
            'admin.customers.show',
            'admin.customers.create',
            'admin.customers.edit',
            'admin.customers.destroy',
            'admin.gift_cards.index',
            'admin.gift_cards.show',
            'admin.gift_cards.create',
            'admin.gift_cards.edit',
            'admin.gift_cards.destroy',
            'admin.gift_cards.redeem',
            'admin.gift_cards.top_up',
            'admin.gift_cards.analytics',
            'admin.suppliers.index',
            'admin.suppliers.show',
            'admin.suppliers.create',
            'admin.suppliers.edit',
            'admin.suppliers.destroy',
            'admin.ingredients.index',
            'admin.ingredients.show',
            'admin.ingredients.create',
            'admin.ingredients.edit',
            'admin.ingredients.destroy',
            'admin.wastage.index',
            'admin.wastage.create',
            'admin.employee_compensations.index',
            'admin.employee_compensations.show',
            'admin.employee_compensations.create',
            'admin.employee_compensations.edit',
            'admin.employee_compensations.destroy',
            'admin.tables.merge',
            'admin.tables.transfer',
            'admin.tables.split',
            'admin.tables.index',
            'admin.tables.show',
            'admin.tables.viewer',
            'admin.pos.index',
            'admin.pos_terminal_devices.index',
            'admin.pos_terminal_devices.edit',
            'admin.pos.kitchen_viewer',
            'admin.pos.kitchen_stations',
            'admin.products.index',
            'admin.products.show',
            'admin.products.create',
            'admin.products.edit',
            'admin.menus.index',
            'admin.menus.show',
            'admin.menus.create',
            'admin.menus.edit',
            'admin.price_types.index',
            'admin.price_types.show',
            'admin.price_types.create',
            'admin.orders.index',
            'admin.orders.show',
            'admin.orders.create',
            'admin.orders.edit',
            'admin.orders.active',
            'admin.orders.print',
            'admin.orders.receive_payment',
            'admin.orders.update_status',
            'admin.reports.index',
            'admin.reports.sales',
            'admin.invoices.index',
            'admin.invoices.show',
            'admin.notifications.index',
        ];

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'api');
        }

        $this->user->givePermissionTo($permissions);
        Sanctum::actingAs($this->user, ['*'], 'api');
    }

    protected function useRealDatabaseConnection(): void
    {
        $environmentPath = base_path('.env');
        if (! file_exists($environmentPath)) {
            $this->markTestSkipped('A local .env file is required for real database API smoke tests.');
        }

        $environment = \Dotenv\Dotenv::parse(file_get_contents($environmentPath));
        $connection = $environment['DB_CONNECTION'] ?? null;
        $database = $environment['DB_DATABASE'] ?? null;

        if ($connection !== 'mysql' || blank($database)) {
            $this->markTestSkipped('Real database API smoke tests require a configured MySQL database in .env.');
        }

        config([
            'app.installed' => true,
            'database.default' => $connection,
            "database.connections.{$connection}.host" => $environment['DB_HOST'] ?? config("database.connections.{$connection}.host"),
            "database.connections.{$connection}.port" => $environment['DB_PORT'] ?? config("database.connections.{$connection}.port"),
            "database.connections.{$connection}.database" => $database,
            "database.connections.{$connection}.username" => $environment['DB_USERNAME'] ?? config("database.connections.{$connection}.username"),
            "database.connections.{$connection}.password" => $environment['DB_PASSWORD'] ?? config("database.connections.{$connection}.password"),
        ]);

        DB::purge($connection);
        DB::reconnect($connection);
    }

    protected function tearDown(): void
    {
        if ($this->transactionStarted) {
            DB::rollBack();
        }

        parent::tearDown();
    }

    public function test_auth_customer_and_gift_card_api_crud_flows_work_with_real_database(): void
    {
        $this->get('/api/v1/imports/templates/orders')
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $tokenResponse = $this->postJson('/api/v1/auth/api-tokens', [
            'name' => 'smoke-'.Str::lower(Str::random(6)),
            'abilities' => ['*'],
        ])
            ->assertOk()
            ->assertJsonPath('body.abilities.0', '*');

        $this->assertNotEmpty($tokenResponse->json('body.token'));

        $this->getJson('/api/v1/auth/sessions')
            ->assertOk();

        $this->getJson('/api/v1/auth/api-tokens')
            ->assertOk();

        $this->getJson('/api/v1/app/boot-data')
            ->assertOk()
            ->assertJsonMissingPath('body.currency_settings.forge_api_key')
            ->assertJsonMissingPath('body.currency_settings.fixer_access_key')
            ->assertJsonMissingPath('body.currency_settings.currency_data_feed_api_key');

        $customerEmail = 'smoke-'.Str::lower(Str::random(10)).'@nexdine.test';
        $customerPhone = '79'.random_int(1000000, 9999999);

        $customerId = $this->postJson('/api/v1/customers/quick-store', [
            'name' => 'Smoke Customer',
            'email' => $customerEmail,
            'phone' => $customerPhone,
            'phone_country_iso_code' => 'JO',
            'branch_id' => $this->branch->id,
        ])
            ->assertCreated()
            ->json('body.id');

        $this->assertNotEmpty($customerId);

        $this->getJson('/api/v1/customers?with_filters=1')
            ->assertOk();

        $this->getJson("/api/v1/customers/{$customerId}")
            ->assertOk()
            ->assertJsonPath('body.id', $customerId);

        $this->putJson("/api/v1/customers/{$customerId}", [
            'name' => 'Smoke Customer Updated',
            'email' => $customerEmail,
            'phone' => $customerPhone,
            'phone_country_iso_code' => 'JO',
            'is_active' => true,
        ])
            ->assertOk()
            ->assertJsonPath('body.name', 'Smoke Customer Updated');

        $this->getJson("/api/v1/customers/{$customerId}/detail")
            ->assertOk()
            ->assertJsonPath('body.customer.id', $customerId);

        $giftCardCode = 'SMOKE-'.Str::upper(Str::random(10));

        $giftCardId = $this->postJson('/api/v1/gift-cards', [
            'branch_id' => $this->branch->id,
            'customer_id' => $customerId,
            'code' => $giftCardCode,
            'initial_balance' => 100,
            'status' => 'active',
            'issued_at' => now()->toDateTimeString(),
            'expires_at' => now()->addMonth()->toDateTimeString(),
            'notes' => 'Smoke test gift card',
        ])
            ->assertOk()
            ->assertJsonPath('body.code', $giftCardCode)
            ->json('body.id');

        $this->assertNotEmpty($giftCardId);

        $this->getJson('/api/v1/gift-cards')
            ->assertOk();

        $this->getJson("/api/v1/gift-cards/{$giftCardId}")
            ->assertOk()
            ->assertJsonPath('body.id', $giftCardId);

        $this->putJson("/api/v1/gift-cards/{$giftCardId}", [
            'customer_id' => $customerId,
            'status' => 'active',
            'expires_at' => now()->addMonths(2)->toDateTimeString(),
            'notes' => 'Smoke test gift card updated',
        ])
            ->assertOk()
            ->assertJsonPath('body.notes', 'Smoke test gift card updated');

        $this->postJson("/api/v1/gift-cards/{$giftCardId}/top-up", [
            'amount' => 25,
            'notes' => 'Smoke top up',
        ])
            ->assertOk()
            ->assertJsonPath('body.current_balance', '125.0000');

        $this->postJson("/api/v1/gift-cards/{$giftCardId}/redeem", [
            'amount' => 10,
            'notes' => 'Smoke redeem',
        ])
            ->assertOk()
            ->assertJsonPath('body.current_balance', '115.0000');

        $this->getJson('/api/v1/gift-cards/analytics?branch_id='.$this->branch->id)
            ->assertOk()
            ->assertJsonStructure(['body' => ['total_issued', 'active_count']]);

        $this->deleteJson("/api/v1/gift-cards/{$giftCardId}")
            ->assertOk();

        $this->deleteJson("/api/v1/customers/{$customerId}")
            ->assertOk();
    }

    public function test_qr_login_survives_cache_loss_and_prevents_replay_with_real_database(): void
    {
        $permission = Permission::findOrCreate('admin.users.edit', 'api');
        $this->user->givePermissionTo($permission);

        $token = $this->postJson('/api/v1/auth/qr-token', [
            'user_id' => $this->user->id,
        ])
            ->assertOk()
            ->assertJsonStructure(['body' => ['token', 'expires_at', 'expires_in_seconds']])
            ->json('body.token');

        Cache::flush();

        $this->assertDatabaseHas('user_qr_login_tokens', [
            'user_id' => $this->user->id,
            'token_hash' => hash('sha256', $token),
        ]);

        $this->getJson('/api/v1/auth/qr-token/status?token='.urlencode($token))
            ->assertOk()
            ->assertJsonPath('body.status', 'pending');

        $this->postJson('/api/v1/auth/qr-login', ['token' => $token])
            ->assertOk()
            ->assertJsonPath('body.user.id', $this->user->id)
            ->assertJsonStructure(['body' => ['token', 'expires_at']]);

        $this->getJson('/api/v1/auth/qr-token/status?token='.urlencode($token))
            ->assertOk()
            ->assertJsonPath('body.status', 'consumed');

        $this->postJson('/api/v1/auth/qr-login', ['token' => $token])
            ->assertUnauthorized();
    }

    public function test_operational_api_flows_create_linked_inventory_and_employee_records(): void
    {
        $unit = Unit::query()->firstOrFail();

        $supplierId = $this->postJson('/api/v1/suppliers', [
            'name' => 'Smoke Supplier',
            'branch_id' => $this->branch->id,
            'address' => 'Smoke Street',
            'phone' => '790000001',
            'email' => 'smoke-supplier@nexdine.test',
        ])
            ->assertCreated()
            ->json('body.id');

        $ingredientId = $this->postJson('/api/v1/ingredients', [
            'name' => ['en' => 'Smoke Tomato', 'ar' => 'طماطم اختبارية'],
            'branch_id' => $this->branch->id,
            'unit_id' => $unit->id,
            'cost_per_unit' => 2.5,
            'alert_quantity' => 3,
            'is_returnable' => true,
        ])
            ->assertCreated()
            ->json('body.id');

        $this->postJson("/api/v1/ingredients/{$ingredientId}/quick-adjust", [
            'current_stock' => 10,
            'reason' => 'Smoke opening stock',
        ])
            ->assertOk()
            ->assertJsonPath('body.new_stock', 10);

        $this->postJson('/api/v1/wastage', [
            'ingredient_id' => $ingredientId,
            'quantity' => 1.5,
            'reason' => 'spoiled',
            'branch_id' => $this->branch->id,
            'note' => 'Smoke wastage',
        ])
            ->assertCreated();

        $today = now()->toDateString();
        $this->getJson("/api/v1/wastage/report?from={$today}&to={$today}&branch_id={$this->branch->id}")
            ->assertOk()
            ->assertJsonFragment(['ingredient_id' => $ingredientId]);

        $this->getJson("/api/v1/wastage/by-reason?from={$today}&to={$today}&branch_id={$this->branch->id}")
            ->assertOk();

        $this->getJson("/api/v1/wastage/trends?from={$today}&to={$today}&branch_id={$this->branch->id}")
            ->assertOk();

        $employee = User::factory()->create([
            'branch_id' => $this->branch->id,
            'is_active' => true,
        ]);

        $compensationId = $this->postJson('/api/v1/employee-compensations', [
            'branch_id' => $this->branch->id,
            'user_id' => $employee->id,
            'pay_type' => 'monthly',
            'base_rate' => 1000,
            'overtime_rate' => 10,
            'standard_daily_minutes' => 480,
            'effective_from' => $today,
            'effective_to' => null,
            'is_active' => true,
            'notes' => 'Smoke compensation',
        ])
            ->assertCreated()
            ->json('body.id');

        $this->getJson('/api/v1/employee-compensations?with_filters=1')
            ->assertOk();

        $this->putJson("/api/v1/employee-compensations/{$compensationId}", [
            'branch_id' => $this->branch->id,
            'user_id' => $employee->id,
            'pay_type' => 'hourly',
            'base_rate' => 12,
            'overtime_rate' => 15,
            'standard_daily_minutes' => 480,
            'effective_from' => $today,
            'effective_to' => null,
            'is_active' => true,
            'notes' => 'Smoke compensation updated',
        ])
            ->assertOk()
            ->assertJsonPath('body.pay_type.id', 'hourly');

        $this->deleteJson("/api/v1/employee-compensations/{$compensationId}")
            ->assertOk();

        $this->deleteJson("/api/v1/ingredients/{$ingredientId}")
            ->assertOk();

        $this->deleteJson("/api/v1/suppliers/{$supplierId}")
            ->assertOk();
    }

    public function test_mfa_setup_and_login_challenge_work_with_real_database(): void
    {
        $setupResponse = $this->postJson('/api/v1/accounts/mfa/setup')
            ->assertOk();

        $secret = $setupResponse->json('body.secret');
        $this->assertNotEmpty($secret);

        $code = $this->totpCode($secret);

        $this->postJson('/api/v1/accounts/mfa/confirm', ['code' => $code])
            ->assertOk()
            ->assertJsonCount(8, 'body.recovery_codes');

        $loginResponse = $this->postJson('/api/v1/auth/login', [
            'identifier' => $this->user->username,
            'password' => 'password',
        ])
            ->assertOk()
            ->assertJsonPath('body.mfa_required', true);

        $this->postJson('/api/v1/auth/mfa/verify', [
            'challenge_token' => $loginResponse->json('body.challenge_token'),
            'code' => $this->totpCode($secret),
        ])
            ->assertOk()
            ->assertJsonStructure(['body' => ['user', 'token']]);
    }

    public function test_capacity_table_merge_does_not_require_pos_session_fields(): void
    {
        $floor = \Modules\SeatingPlan\Models\Floor::factory()->create([
            'branch_id' => $this->branch->id,
            'created_by' => $this->user->id,
            'name' => ['en' => 'Smoke Floor'],
            'order' => 1,
            'is_active' => true,
        ]);
        $zone = \Modules\SeatingPlan\Models\Zone::factory()->create([
            'branch_id' => $this->branch->id,
            'floor_id' => $floor->id,
            'created_by' => $this->user->id,
            'name' => ['en' => 'Smoke Zone'],
            'order' => 1,
            'is_active' => true,
        ]);
        $tables = \Modules\SeatingPlan\Models\Table::factory()
            ->count(2)
            ->create([
                'branch_id' => $this->branch->id,
                'floor_id' => $floor->id,
                'zone_id' => $zone->id,
                'created_by' => $this->user->id,
                'name' => ['en' => 'Smoke Table'],
                'order' => 1,
                'status' => \Modules\SeatingPlan\Enums\TableStatus::Available,
            ]);

        $this->postJson("/api/v1/tables/viewer/{$tables[0]->id}/merge", [
            'branch_id' => $this->branch->id,
            'table_ids' => [$tables[1]->id],
            'type' => 'capacity',
        ])->assertOk();

        $tables->each(function ($table) {
            $this->assertNotNull($table->fresh()->current_merge_id);
        });

        $this->postJson("/api/v1/tables/viewer/{$tables[0]->id}/split")
            ->assertOk();

        $tables->each(function ($table) {
            $table->refresh();
            $this->assertNull($table->current_merge_id);
            $this->assertSame(
                \Modules\SeatingPlan\Enums\TableStatus::Available,
                $table->status
            );
        });
    }

    public function test_table_transfer_moves_active_orders_to_available_target_table(): void
    {
        $floor = \Modules\SeatingPlan\Models\Floor::factory()->create([
            'branch_id' => $this->branch->id,
            'created_by' => $this->user->id,
            'name' => ['en' => 'Smoke Transfer Floor'],
            'order' => 1,
            'is_active' => true,
        ]);
        $zone = \Modules\SeatingPlan\Models\Zone::factory()->create([
            'branch_id' => $this->branch->id,
            'floor_id' => $floor->id,
            'created_by' => $this->user->id,
            'name' => ['en' => 'Smoke Transfer Zone'],
            'order' => 1,
            'is_active' => true,
        ]);
        $sourceTable = \Modules\SeatingPlan\Models\Table::factory()->create([
            'branch_id' => $this->branch->id,
            'floor_id' => $floor->id,
            'zone_id' => $zone->id,
            'created_by' => $this->user->id,
            'name' => ['en' => 'Transfer Source'],
            'order' => 1,
            'status' => \Modules\SeatingPlan\Enums\TableStatus::Occupied,
        ]);
        $targetTable = \Modules\SeatingPlan\Models\Table::factory()->create([
            'branch_id' => $this->branch->id,
            'floor_id' => $floor->id,
            'zone_id' => $zone->id,
            'created_by' => $this->user->id,
            'name' => ['en' => 'Transfer Target'],
            'order' => 2,
            'status' => \Modules\SeatingPlan\Enums\TableStatus::Available,
        ]);

        $order = new \Modules\Order\Models\Order([
            'branch_id' => $this->branch->id,
            'table_id' => $sourceTable->id,
            'waiter_id' => $this->user->id,
            'status' => \Modules\Order\Enums\OrderStatus::Pending,
            'type' => \Modules\Order\Enums\OrderType::DineIn,
            'payment_status' => \Modules\Order\Enums\OrderPaymentStatus::Unpaid,
            'currency' => 'USD',
            'currency_rate' => 1,
            'subtotal' => 0,
            'total' => 0,
            'guest_count' => 2,
            'order_date' => now()->toDateString(),
        ]);
        $order->created_by = $this->user->id;
        $order->save();

        $this->patchJson("/api/v1/tables/viewer/{$sourceTable->id}/transfer", [
            'target_table_id' => $targetTable->id,
        ])->assertOk();

        $this->assertSame($targetTable->id, $order->refresh()->table_id);
        $this->assertSame(\Modules\SeatingPlan\Enums\TableStatus::Available, $sourceTable->refresh()->status);
        $this->assertSame(\Modules\SeatingPlan\Enums\TableStatus::Occupied, $targetTable->refresh()->status);
    }

    public function test_pos_product_creation_and_price_type_contexts_are_consistent(): void
    {
        $context = $this->makePosContext();
        $priceTypeId = $this->postJson('/api/v1/price-types', [
            'name' => ['en' => 'QA AC Hall '.Str::random(5), 'ar' => 'قاعة اختبار'],
            'description' => ['en' => 'POS API smoke pricing', 'ar' => 'اختبار الاسعار'],
            'rule_type' => 'percent',
            'rule_value' => 10,
            'is_active' => true,
        ])->assertCreated()->json('body.id');

        $context['zone']->update(['price_type_id' => $priceTypeId]);
        Cache::flush();

        $explicitId = $this->createPosProduct($context, 'Explicit Zone Meal', [
            ['price_type_id' => $priceTypeId, 'is_global' => false, 'price' => 130],
        ], true);
        $globalId = $this->createPosProduct($context, 'Global Zone Meal', [
            ['price_type_id' => $priceTypeId, 'is_global' => true, 'price' => null],
        ]);
        $zeroId = $this->createPosProduct($context, 'Zero Zone Meal', [
            ['price_type_id' => $priceTypeId, 'is_global' => false, 'price' => 0],
        ]);
        $selfServicePriceType = \Modules\Pricing\Models\PriceType::query()
            ->withoutGlobalActive()
            ->where('code', 'SELF_SERVICE')
            ->first();
        if (! $selfServicePriceType) {
            $selfServicePriceType = \Modules\Pricing\Models\PriceType::query()->create([
                'name' => ['en' => 'Self Service', 'ar' => 'خدمة ذاتية'],
                'code' => 'SELF_SERVICE',
                'rule_type' => 'fixed',
                'rule_value' => 0,
                'is_active' => true,
            ]);
        } elseif (! $selfServicePriceType->is_active) {
            $selfServicePriceType->update(['is_active' => true]);
        }
        $selfServiceId = $this->createPosProduct($context, 'Self Service Meal', [
            ['price_type_id' => $selfServicePriceType->id, 'is_global' => false, 'price' => 85],
        ]);
        Cache::flush();

        $shown = $this->getJson("/api/v1/products/{$explicitId}?menu_id={$context['menu']->id}")
            ->assertOk()
            ->assertJsonCount(3, 'body.options');
        $this->assertSame('select', $shown->json('body.options.0.type.id'));

        $cartId = (string) Str::uuid();
        $dineIn = $this->getJson(
            "/api/v1/pos/viewer/{$cartId}/menu-items/{$context['menu']->id}?order_type=dine_in&table_id={$context['table']->id}"
        )->assertOk();

        $this->assertSame($priceTypeId, $dineIn->json('body.pricing.price_type_id'));
        $this->assertSame(130, $this->posPrice($dineIn->json('body.products'), $explicitId));
        $this->assertSame(110, $this->posPrice($dineIn->json('body.products'), $globalId));
        $this->assertSame(100, $this->posPrice($dineIn->json('body.products'), $zeroId));
        $this->assertTrue((bool) collect($dineIn->json('body.products'))->firstWhere('id', $explicitId)['has_options']);
        $this->assertCount(0, collect($dineIn->json('body.products'))->firstWhere('id', $explicitId)['options']);

        $this->getJson(
            "/api/v1/pos/viewer/{$cartId}/menu-items/{$context['menu']->id}/products/{$explicitId}?order_type=dine_in&table_id={$context['table']->id}"
        )
            ->assertOk()
            ->assertJsonCount(3, 'body.options')
            ->assertJsonPath('body.selling_price.amount', 130);

        $takeaway = $this->getJson(
            "/api/v1/pos/viewer/{$cartId}/menu-items/{$context['menu']->id}?order_type=takeaway&table_id={$context['table']->id}"
        )->assertOk();
        $this->assertNull($takeaway->json('body.pricing.price_type_id'));
        $this->assertSame(100, $this->posPrice($takeaway->json('body.products'), $explicitId));
        $this->assertSame(100, $this->posPrice($takeaway->json('body.products'), $globalId));

        $selfService = $this->getJson(
            "/api/v1/pos/viewer/{$cartId}/menu-items/{$context['menu']->id}?order_type=self_service"
        )->assertOk();
        $this->assertSame($selfServicePriceType->id, $selfService->json('body.pricing.price_type_id'));
        $this->assertSame(85, $this->posPrice($selfService->json('body.products'), $selfServiceId));
    }

    public function test_pos_table_menu_responses_expose_branch_order_types_and_filter_metadata(): void
    {
        $context = $this->makePosContext();
        $cartId = (string) Str::uuid();
        $deliveryMenuId = $this->postJson('/api/v1/menus', [
            'branch_id' => $this->branch->id,
            'name' => ['en' => 'Delivery QA Menu', 'ar' => 'قائمة التوصيل'],
            'description' => ['en' => 'Delivery only menu', 'ar' => 'قائمة التوصيل فقط'],
            'order_types' => [OrderType::Delivery->value],
            'is_active' => true,
        ])->assertCreated()
            ->assertJsonPath('body.order_types.0', OrderType::Delivery->value)
            ->json('body.id');
        $selfServiceMenuId = $this->postJson('/api/v1/menus', [
            'branch_id' => $this->branch->id,
            'name' => ['en' => 'Self Service QA Menu', 'ar' => 'قائمة الخدمة الذاتية'],
            'description' => ['en' => 'Self service menu', 'ar' => 'قائمة الخدمة الذاتية'],
            'order_types' => [OrderType::SelfService->value],
            'is_active' => true,
        ])->assertCreated()
            ->assertJsonPath('body.order_types.0', OrderType::SelfService->value)
            ->json('body.id');
        $secondSelfServiceMenuId = $this->postJson('/api/v1/menus', [
            'branch_id' => $this->branch->id,
            'name' => ['en' => 'Kiosk Self Service QA Menu', 'ar' => 'قائمة كشك الخدمة الذاتية'],
            'description' => ['en' => 'Second self service menu', 'ar' => 'قائمة خدمة ذاتية ثانية'],
            'order_types' => [OrderType::SelfService->value],
            'is_active' => true,
        ])->assertCreated()->json('body.id');
        $inactiveSelfServiceMenuId = $this->postJson('/api/v1/menus', [
            'branch_id' => $this->branch->id,
            'name' => ['en' => 'Inactive Self Service QA Menu', 'ar' => 'قائمة خدمة ذاتية غير نشطة'],
            'description' => ['en' => 'Inactive menu', 'ar' => 'قائمة غير نشطة'],
            'order_types' => [OrderType::SelfService->value],
            'is_active' => false,
        ])->assertCreated()->json('body.id');
        $selfServiceMenu = Menu::query()->withoutGlobalActive()->findOrFail($selfServiceMenuId);
        $selfServiceCategory = Category::query()->create([
            'menu_id' => $selfServiceMenu->id,
            'name' => ['en' => 'Self Service Items', 'ar' => 'اصناف الخدمة الذاتية'],
            'is_active' => true,
        ]);
        $selfServiceProductId = $this->createPosProduct(
            [...$context, 'menu' => $selfServiceMenu, 'category' => $selfServiceCategory],
            'Self Service Counter Meal'
        );
        Cache::flush();

        $adminConfig = $this->getJson("/api/v1/pos/viewer/{$cartId}/configuration")
            ->assertOk();
        $this->assertContains(OrderType::Takeaway->value, collect($adminConfig->json('body.order_types'))->pluck('id')->all());
        $menuIds = collect($adminConfig->json('body.menus'))->pluck('id')->all();
        $this->assertContains($context['menu']->id, $menuIds);
        $this->assertContains($deliveryMenuId, $menuIds);
        $this->assertContains($selfServiceMenuId, $menuIds);
        $this->assertContains($secondSelfServiceMenuId, $menuIds);
        $this->assertNotContains($inactiveSelfServiceMenuId, $menuIds);
        $this->assertTrue($context['menu']->refresh()->is_active);
        $this->assertTrue($selfServiceMenu->refresh()->is_active);
        $this->getJson(
            "/api/v1/pos/viewer/{$cartId}/menu-items/{$deliveryMenuId}?order_type=delivery"
        )->assertOk();
        $this->getJson(
            "/api/v1/pos/viewer/{$cartId}/menu-items/{$deliveryMenuId}?order_type=dine_in"
        )->assertUnprocessable();
        $selfServiceItems = $this->getJson(
            "/api/v1/pos/viewer/{$cartId}/menu-items/{$selfServiceMenuId}?order_type=self_service"
        )->assertOk();
        $this->assertContains($selfServiceProductId, collect($selfServiceItems->json('body.products'))->pluck('id')->all());
        $selfServiceCartId = (string) Str::uuid();
        $this->postJson("/api/v1/cart/{$selfServiceCartId}/order-types/self_service")->assertOk();
        $this->postJson("/api/v1/cart/{$selfServiceCartId}/items", [
            'product_id' => $selfServiceProductId,
            'qty' => 1,
        ])->assertOk();
        $selfServicePayload = $this->orderPayload(
            [...$context, 'menu' => $selfServiceMenu],
            OrderType::SelfService->value
        );
        $this->withHeader('Idempotency-Key', 'qa-self-service-wrong-menu-'.Str::uuid())
            ->postJson("/api/v1/orders/{$selfServiceCartId}", [
                ...$selfServicePayload,
                'menu_id' => $deliveryMenuId,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('menu_id');
        $selfServiceCreated = $this->withHeader('Idempotency-Key', 'qa-self-service-order-'.Str::uuid())
            ->postJson("/api/v1/orders/{$selfServiceCartId}", $selfServicePayload)
            ->assertCreated();
        $this->assertSame(
            OrderType::SelfService,
            Order::query()->where('reference_no', $selfServiceCreated->json('body.order_id'))->firstOrFail()->type
        );
        $selfServiceOrder = Order::query()
            ->where('reference_no', $selfServiceCreated->json('body.order_id'))
            ->firstOrFail();

        $resumeCartId = (string) Str::uuid();
        $this->getJson("/api/v1/orders/{$resumeCartId}/{$selfServiceOrder->id}/edit")
            ->assertOk();
        $this->withHeader('Idempotency-Key', 'qa-self-service-resume-'.Str::uuid())
            ->putJson("/api/v1/orders/{$resumeCartId}/{$selfServiceOrder->id}/update", [
                ...$selfServicePayload,
                'submit_action' => 'send_to_kitchen',
            ])
            ->assertOk();
        $this->assertSame(OrderStatus::Confirmed, $selfServiceOrder->refresh()->status);
        $this->assertNull($selfServiceOrder->table_id);

        $this->getJson("/api/v1/orders/{$selfServiceCartId}/{$selfServiceOrder->id}/edit")
            ->assertOk();
        $createdFromEditCart = $this->withHeader('Idempotency-Key', 'qa-self-service-reused-edit-cart-'.Str::uuid())
            ->postJson("/api/v1/orders/{$selfServiceCartId}", $selfServicePayload)
            ->assertCreated();
        $newSelfServiceOrder = Order::query()
            ->where('reference_no', $createdFromEditCart->json('body.order_id'))
            ->firstOrFail();
        $this->assertNotSame($selfServiceOrder->id, $newSelfServiceOrder->id);
        $this->assertCount(1, $newSelfServiceOrder->products);
        $this->getJson(
            "/api/v1/pos/viewer/{$cartId}/menu-items/{$inactiveSelfServiceMenuId}?order_type=self_service"
        )->assertUnprocessable();
        $this->getJson(
            "/api/v1/pos/viewer/{$cartId}/menu-items/{$inactiveSelfServiceMenuId}"
        )->assertUnprocessable();

        $tables = $this->getJson('/api/v1/tables/viewer')
            ->assertOk();
        $this->assertContains($context['floor']->id, collect($tables->json('body.filters.floors'))->pluck('id')->all());
        $this->assertContains($context['zone']->id, collect($tables->json('body.filters.zones'))->pluck('id')->all());
        $this->assertContains(TableStatus::Available->value, collect($tables->json('body.filters.statuses'))->pluck('id')->all());

        $waiter = User::factory()->create([
            'branch_id' => $this->branch->id,
            'is_active' => true,
            'username' => 'pos_waiter_'.Str::lower(Str::random(8)),
        ]);
        $waiter->syncRoles([DefaultRole::Waiter->value]);

        $this->postJson('/api/v1/auth/login', [
            'identifier' => $waiter->username,
            'password' => 'password',
        ])
            ->assertOk()
            ->assertJsonStructure(['body' => ['user', 'token']]);

        Sanctum::actingAs($waiter, ['*'], 'api');

        $waiterConfig = $this->getJson('/api/v1/pos/viewer/'.Str::uuid().'/configuration')
            ->assertOk();
        $this->assertEqualsCanonicalizing(
            $this->branch->refresh()->order_types,
            collect($waiterConfig->json('body.order_types'))->pluck('id')->all(),
        );

        $this->getJson('/api/v1/tables/viewer')
            ->assertOk()
            ->assertJsonFragment(['id' => $context['table']->id]);
    }

    public function test_pos_order_billing_payment_idempotency_and_print_flows_preserve_totals(): void
    {
        $context = $this->makePosContext();
        $productId = $this->createPosProduct($context, 'Billing Meal');
        $this->createGlobalVatTax();

        $dineInCartId = (string) Str::uuid();
        $this->getJson("/api/v1/pos/viewer/{$dineInCartId}/configuration")->assertOk();
        $this->postJson("/api/v1/cart/{$dineInCartId}/order-types/dine_in", [
            'table_id' => $context['table']->id,
        ])->assertOk();
        $this->postJson("/api/v1/cart/{$dineInCartId}/items", [
            'product_id' => $productId,
            'qty' => 1,
            'table_id' => $context['table']->id,
        ])->assertOk();
        $invalidOrderPayload = $this->orderPayload($context, OrderType::DineIn->value);
        $expiredKey = 'qa-expired-order-'.Str::uuid();
        DB::table('idempotency_keys')->insert([
            'key_hash' => hash('sha256', implode('|', [
                $this->user->id,
                'POST',
                "api/v1/orders/{$dineInCartId}",
                $expiredKey,
            ])),
            'user_id' => $this->user->id,
            'method' => 'POST',
            'route' => "api/v1/orders/{$dineInCartId}",
            'request_hash' => hash('sha256', json_encode($invalidOrderPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
            'status' => 'processing',
            'locked_until' => now()->subMinute(),
            'created_at' => now()->subMinutes(6),
            'updated_at' => now()->subMinutes(6),
        ]);

        $this->withHeader('Idempotency-Key', $expiredKey)
            ->postJson("/api/v1/orders/{$dineInCartId}", $invalidOrderPayload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('table_id');
        $this->withHeader('Idempotency-Key', $expiredKey)
            ->postJson("/api/v1/orders/{$dineInCartId}", $invalidOrderPayload)
            ->assertUnprocessable()
            ->assertHeader('X-NexDine-Idempotent-Replay', '1');

        $this->deleteJson("/api/v1/cart/{$dineInCartId}/clear")->assertOk();
        $takeawayCartId = $dineInCartId;
        $this->postJson("/api/v1/cart/{$takeawayCartId}/order-types/takeaway")->assertOk();
        $cartResponse = $this->postJson("/api/v1/cart/{$takeawayCartId}/items", [
            'product_id' => $productId,
            'qty' => 1,
        ])->assertOk();
        $itemId = $cartResponse->json('body.items.0.id');

        $cartResponse = $this->putJson("/api/v1/cart/{$takeawayCartId}/items/{$itemId}", [
            'qty' => 2,
        ])->assertOk();
        $this->assertSame(2, $cartResponse->json('body.quantity'));
        $this->assertEquals(200, $cartResponse->json('body.subTotal.amount'));
        $taxTotal = collect($cartResponse->json('body.taxes'))
            ->sum(fn (array $tax) => (float) data_get($tax, 'amount.amount', 0));
        $cartTotal = (float) $cartResponse->json('body.total.amount');
        $this->assertGreaterThanOrEqual(30, $taxTotal);
        $this->assertEquals(200 + $taxTotal, $cartTotal);

        $headers = ['Idempotency-Key' => 'qa-order-'.Str::uuid()];
        $created = $this->withHeaders($headers)->postJson(
            "/api/v1/orders/{$takeawayCartId}",
            $this->orderPayload($context, OrderType::Takeaway->value)
        )->assertCreated();
        $reference = $created->json('body.order_id');
        $order = Order::query()->where('reference_no', $reference)->firstOrFail();

        $this->withHeaders($headers)->postJson(
            "/api/v1/orders/{$takeawayCartId}",
            $this->orderPayload($context, OrderType::Takeaway->value)
        )
            ->assertCreated()
            ->assertHeader('X-NexDine-Idempotent-Replay', '1');
        $this->assertSame(1, Order::query()->where('reference_no', $reference)->count());

        $shown = $this->getJson("/api/v1/orders/{$order->id}/show")->assertOk();
        $this->assertEquals(200, $shown->json('body.subtotal.original.amount'));
        $this->assertEquals($cartTotal, $shown->json('body.total.original.amount'));
        $this->assertEquals($cartTotal, $shown->json('body.due_amount.original.amount'));

        $this->getJson("/api/v1/orders/{$order->id}/payments/meta")->assertOk();

        $this->withHeaders(['Idempotency-Key' => 'qa-payment-partial-'.Str::uuid()])
            ->postJson("/api/v1/orders/{$order->id}/payments", [
                'payments' => [['method' => PaymentMethod::Card->value, 'amount' => 80]],
                'payment_mode' => 'partial',
                'with_print' => false,
                'register_id' => $context['register']->id,
                'session_id' => $context['session']->id,
            ])->assertOk();

        $partiallyPaid = $this->getJson("/api/v1/orders/{$order->id}/show")->assertOk();
        $remainingAmount = $cartTotal - 80;
        $this->assertEquals($remainingAmount, $partiallyPaid->json('body.due_amount.original.amount'));
        $this->assertSame('partially_paid', $partiallyPaid->json('body.payment_status.id'));

        $this->withHeaders(['Idempotency-Key' => 'qa-payment-full-'.Str::uuid()])
            ->postJson("/api/v1/orders/{$order->id}/payments", [
                'payments' => [['method' => PaymentMethod::Cash->value, 'amount' => $remainingAmount]],
                'payment_mode' => 'full',
                'with_print' => true,
                'customer_given_amount' => $remainingAmount + 10,
                'change_return' => 10,
                'register_id' => $context['register']->id,
                'session_id' => $context['session']->id,
            ])->assertOk();

        $paid = $this->getJson("/api/v1/orders/{$order->id}/show")->assertOk();
        $this->assertEquals(0, $paid->json('body.due_amount.original.amount'));
        $this->assertSame('paid', $paid->json('body.payment_status.id'));
        $this->assertCount(2, $paid->json('body.payments'));

        $invoiceId = $paid->json('body.invoices.0.id');
        $this->assertNotNull($invoiceId);
        $this->getJson("/api/v1/invoices/{$invoiceId}/show")
            ->assertOk()
            ->assertJsonPath('body.qrcode_mime_type', extension_loaded('imagick') ? 'image/png' : 'image/svg+xml');

        $this->getJson("/api/v1/orders/{$order->id}/print")
            ->assertOk()
            ->assertJsonFragment(['id' => 'invoice']);
        setting([
            'appearance_print_terms' => 'QA receipt terms from settings',
            'appearance_footer_text' => 'QA printed footer',
        ]);
        app(\Modules\Setting\Services\Setting\SettingServiceInterface::class)->refreshSettingBinding();

        $billPreview = $this->getJson("/api/v1/orders/{$order->id}/print/bill/preview")
            ->assertOk();
        $invoicePreview = $this->getJson("/api/v1/orders/{$order->id}/print/invoice/preview")
            ->assertOk();
        foreach ([$billPreview->json('body'), $invoicePreview->json('body')] as $printedHtml) {
            $this->assertStringContainsString('QA receipt terms from settings', $printedHtml);
            $this->assertStringContainsString('QA printed footer', $printedHtml);
            $this->assertStringContainsString($this->branch->address_line1, $printedHtml);
            $this->assertStringContainsString($this->branch->phone, $printedHtml);
        }
        $this->assertStringContainsString('Takeaway : -', $invoicePreview->json('body'));

        $order->update([
            'type' => OrderType::DineIn,
            'table_id' => $context['table']->id,
        ]);
        $dineInInvoicePreview = $this->getJson("/api/v1/orders/{$order->id}/print/invoice/preview")
            ->assertOk();
        $this->assertStringContainsString('Dine In : '.$context['table']->name, $dineInInvoicePreview->json('body'));

        $printer = Printer::query()->create([
            'branch_id' => $this->branch->id,
            'name' => ['en' => 'QA POS-80', 'ar' => 'طابعة اختبار'],
            'connection_type' => PrinterConnectionType::Tcp,
            'options' => ['host' => '127.0.0.1', 'port' => 9100, 'paper_size' => '80mm'],
            'is_active' => true,
        ]);
        $context['register']->update([
            'bill_printer_id' => $printer->id,
            'invoice_printer_id' => $printer->id,
        ]);

        $this->withHeader('Idempotency-Key', '')
            ->postJson("/api/v1/orders/{$order->id}/print/bill", [
                'specific_id' => $context['register']->id,
            ])->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
        $this->withHeader('Idempotency-Key', 'qa-print-bill-'.Str::uuid())
            ->postJson("/api/v1/orders/{$order->id}/print/bill", [
                'specific_id' => $context['register']->id,
            ])->assertOk();
        $this->withHeader('Idempotency-Key', 'qa-print-invoice-'.Str::uuid())
            ->postJson("/api/v1/orders/{$order->id}/print/invoice", [
                'specific_id' => $context['register']->id,
            ])->assertOk();

        // The suite wraps each real-DB test in a transaction. Production print
        // jobs are intentionally dispatched after commit, so execute the two
        // queued handlers here to verify rendered bytes before the rollback.
        $dispatcher = app(PrintDispatcherServiceInterface::class);
        (new DispatchPrintJob(
            $order->id,
            PrintContentType::Bill,
            $context['register']->id,
            true,
        ))->handle($dispatcher);
        (new DispatchPrintJob(
            $order->id,
            PrintContentType::Invoice,
            $context['register']->id,
            true,
        ))->handle($dispatcher);

        $queuedPrints = PrintJob::query()
            ->where('branch_id', $this->branch->id)
            ->latest('created_at')
            ->take(2)
            ->get();
        $this->assertCount(2, $queuedPrints);
        $queuedPrints->each(function (PrintJob $job) {
            $escPosBytes = base64_decode($job->rendered_bytes, true);
            $this->assertIsString($escPosBytes);
            $this->assertStringStartsWith("\x1B\x40", $escPosBytes);
            $this->assertTrue(
                str_contains($escPosBytes, 'BILL') || str_contains($escPosBytes, 'INVOICE'),
                'Receipt payload must contain the expected document title.',
            );
            $this->assertStringContainsString('Billing Meal', $escPosBytes);
            $this->assertSame(1, substr_count($escPosBytes, "\x1D\x56\x00"));
            $this->assertStringEndsWith("\x1D\x56\x00", $escPosBytes);
        });
    }

    public function test_pos_batch_add_uses_explicit_order_type_instead_of_stale_cart_tax_context(): void
    {
        $context = $this->makePosContext();
        $productId = $this->createPosProduct($context, 'Dine In Tax Context Meal');
        Tax::query()->create([
            'branch_id' => $this->branch->id,
            'name' => ['en' => 'Dine In VAT', 'ar' => 'ضريبة داخل المطعم'],
            'code' => 'POS-QA-DINE-'.Str::upper(Str::random(5)),
            'rate' => 15,
            'type' => TaxType::Exclusive,
            'compound' => false,
            'order_types' => [OrderType::DineIn->value],
            'is_global' => true,
            'is_active' => true,
        ]);
        Cache::flush();

        $cartId = (string) Str::uuid();
        $this->getJson("/api/v1/pos/viewer/{$cartId}/configuration")->assertOk();
        $this->postJson("/api/v1/cart/{$cartId}/order-types/takeaway", [
            'branch_id' => $this->branch->id,
        ])
            ->assertOk()
            ->assertJsonPath('body.orderType.id', OrderType::Takeaway->value);

        $tenantDomain = 'qa-'.Str::lower(Str::random(10)).'.nexdine.test';
        $tenant = Tenant::query()->withoutGlobalScopes()->create([
            'name' => 'Public Cart QA',
            'slug' => 'public-cart-'.Str::lower(Str::random(10)),
            'domain' => $tenantDomain,
            'is_active' => true,
        ]);
        $this->branch->forceFill(['tenant_id' => $tenant->id])->saveQuietly();
        $this->user->forceFill(['tenant_id' => $tenant->id])->saveQuietly();

        $response = $this->withHeader('X-NexDine-Tenant-Domain', $tenantDomain)
            ->postJson("/api/v1/public/cart/{$cartId}/items/batch", [
            'branch_id' => $this->branch->id,
            'order_type' => OrderType::DineIn->value,
            'table_id' => $context['table']->id,
            'items' => [
                ['product_id' => $productId, 'qty' => 1, 'options' => []],
            ],
            ])->assertOk();

        $this->assertSame(OrderType::DineIn->value, $response->json('body.orderType.id'));
        $this->assertGreaterThan(
            0,
            collect($response->json('body.taxes'))
                ->sum(fn (array $tax) => (float) data_get($tax, 'amount.amount', 0))
        );
        $this->assertGreaterThan(
            (float) $response->json('body.subTotal.amount'),
            (float) $response->json('body.total.amount')
        );
    }

    public function test_three_pay_and_fire_orders_always_preserve_gst_on_payment(): void
    {
        $context = $this->makePosContext();
        $productId = $this->createPosProduct($context, 'Pay Fire GST Meal');
        $this->createGlobalVatTax();

        for ($index = 1; $index <= 3; $index++) {
            $cartId = (string) Str::uuid();

            $this->getJson("/api/v1/pos/viewer/{$cartId}/configuration")->assertOk();
            $this->postJson("/api/v1/cart/{$cartId}/order-types/takeaway")->assertOk();
            $cartResponse = $this->postJson("/api/v1/cart/{$cartId}/items", [
                'product_id' => $productId,
                'qty' => 1,
            ])->assertOk();

            $cartTaxTotal = collect($cartResponse->json('body.taxes'))
                ->sum(fn (array $tax) => (float) data_get($tax, 'amount.amount', 0));
            $this->assertGreaterThan(0, $cartTaxTotal, "Cart {$index} should have GST before pay and fire.");

            $created = $this->withHeader('Idempotency-Key', 'qa-pay-fire-order-'.Str::uuid())
                ->postJson("/api/v1/orders/{$cartId}", [
                    ...$this->orderPayload($context, OrderType::Takeaway->value),
                    'submit_action' => 'pay_and_fire',
                ])
                ->assertCreated();

            $order = Order::query()
                ->where('reference_no', $created->json('body.order_id'))
                ->firstOrFail();

            $meta = $this->getJson("/api/v1/orders/{$order->id}/payments/meta")
                ->assertOk();

            $metaTaxTotal = (float) $meta->json('body.order.total_tax.amount');
            $dueAmount = (float) $meta->json('body.order.due_amount.amount');
            $this->assertGreaterThan(0, $metaTaxTotal, "Payment meta {$index} should include GST.");

            $this->withHeader('Idempotency-Key', 'qa-pay-fire-payment-'.Str::uuid())
                ->postJson("/api/v1/orders/{$order->id}/payments", [
                    'payments' => [['method' => PaymentMethod::Cash->value, 'amount' => $dueAmount]],
                    'payment_mode' => 'full',
                    'with_print' => false,
                    'customer_given_amount' => $dueAmount,
                    'change_return' => 0,
                    'register_id' => $context['register']->id,
                    'session_id' => $context['session']->id,
                ])->assertOk();

            $shown = $this->getJson("/api/v1/orders/{$order->id}/show")->assertOk();
            $shownTaxTotal = collect($shown->json('body.taxes'))
                ->sum(fn (array $tax) => (float) data_get($tax, 'amount.original.amount', 0));

            $this->assertGreaterThan(0, $shownTaxTotal, "Paid order {$index} should keep GST.");
            $this->assertEquals(0, $shown->json('body.due_amount.original.amount'));
            $this->assertSame('paid', $shown->json('body.payment_status.id'));
        }
    }

    public function test_quick_item_pay_and_fire_orders_preserve_billing_gst_and_payment(): void
    {
        $context = $this->makePosContext();
        $gstRate = 2.5;
        Tax::query()
            ->withOutGlobalBranchPermission()
            ->withoutGlobalActive()
            ->where(fn ($query) => $query
                ->where('branch_id', $this->branch->id)
                ->orWhereNull('branch_id'))
            ->update(['is_active' => false]);
        $tax = $this->createGlobalVatTax($gstRate);

        for ($index = 1; $index <= 3; $index++) {
            $cartId = (string) Str::uuid();
            $price = 200 + ($index * 10);
            $qty = $index === 2 ? 2 : 1;
            $expectedSubtotal = $price * $qty;
            $expectedTax = app(TaxCalculationService::class)
                ->totalTax($expectedSubtotal, collect([$tax]));
            $expectedTotal = $expectedSubtotal + $expectedTax;

            $this->getJson("/api/v1/pos/viewer/{$cartId}/configuration")->assertOk();
            $this->postJson("/api/v1/cart/{$cartId}/order-types/takeaway")->assertOk();

            $cartResponse = $this->postJson("/api/v1/cart/{$cartId}/items/quick", [
                'name' => "Quick QA Item {$index}",
                'price' => $price,
                'qty' => $qty,
                'menu_id' => $context['menu']->id,
            ])->assertOk();

            $cartTaxTotal = collect($cartResponse->json('body.taxes'))
                ->sum(fn (array $tax) => (float) data_get($tax, 'amount.amount', 0));

            $this->assertCount(1, $cartResponse->json('body.items'), "Quick cart {$index} should have one line item.");
            $this->assertSame($qty, $cartResponse->json('body.quantity'), "Quick cart {$index} quantity mismatch.");
            $this->assertSame($qty, $cartResponse->json('body.items.0.qty'), "Quick cart {$index} line quantity mismatch.");
            $this->assertSame("Quick QA Item {$index}", $cartResponse->json('body.items.0.item.name'));
            $this->assertEquals($expectedSubtotal, $cartResponse->json('body.subTotal.amount'), "Quick cart {$index} subtotal mismatch.");
            $this->assertEquals($expectedTax, $cartTaxTotal, "Quick cart {$index} GST mismatch.");
            $this->assertEquals($expectedTotal, $cartResponse->json('body.total.amount'), "Quick cart {$index} total mismatch.");

            $created = $this->withHeader('Idempotency-Key', 'qa-quick-item-order-'.$index.'-'.Str::uuid())
                ->postJson("/api/v1/orders/{$cartId}", [
                    ...$this->orderPayload($context, OrderType::Takeaway->value),
                    'submit_action' => 'pay_and_fire',
                ])
                ->assertCreated();

            $order = Order::query()
                ->with(['products', 'taxes'])
                ->where('reference_no', $created->json('body.order_id'))
                ->firstOrFail();

            $this->assertEquals($expectedSubtotal, $order->subtotal->amount(), "Quick order {$index} subtotal mismatch.");
            $this->assertEquals($expectedTotal, $order->total->amount(), "Quick order {$index} total mismatch.");
            $this->assertEquals($expectedTotal, $order->due_amount->amount(), "Quick order {$index} due mismatch before payment.");
            $this->assertEquals(0, $order->products->sum(fn ($product) => $product->tax_total->amount()), "Quick order {$index} product-level GST should stay zero.");
            $this->assertEquals($expectedTax, $order->taxes->sum(fn ($tax) => $tax->amount->amount()), "Quick order {$index} order GST mismatch.");

            $meta = $this->getJson("/api/v1/orders/{$order->id}/payments/meta")->assertOk();
            $metaTaxTotal = (float) $meta->json('body.order.total_tax.amount');
            $dueAmount = (float) $meta->json('body.order.due_amount.amount');

            $this->assertEquals($expectedTax, $metaTaxTotal, "Quick order {$index} payment meta GST mismatch.");
            $this->assertEquals($expectedTotal, $dueAmount, "Quick order {$index} payment meta due mismatch.");

            $this->withHeader('Idempotency-Key', 'qa-quick-item-payment-'.$index.'-'.Str::uuid())
                ->postJson("/api/v1/orders/{$order->id}/payments", [
                    'payments' => [['method' => PaymentMethod::Cash->value, 'amount' => $dueAmount]],
                    'payment_mode' => 'full',
                    'with_print' => false,
                    'customer_given_amount' => $dueAmount,
                    'change_return' => 0,
                    'register_id' => $context['register']->id,
                    'session_id' => $context['session']->id,
                ])->assertOk();

            $shown = $this->getJson("/api/v1/orders/{$order->id}/show")->assertOk();
            $shownTaxTotal = collect($shown->json('body.taxes'))
                ->sum(fn (array $tax) => (float) data_get($tax, 'amount.original.amount', 0));

            $this->assertEquals($expectedTax, $shownTaxTotal, "Paid quick order {$index} should keep GST.");
            $this->assertEquals(0, $shown->json('body.due_amount.original.amount'), "Paid quick order {$index} due should be zero.");
            $this->assertSame('paid', $shown->json('body.payment_status.id'), "Paid quick order {$index} should be paid.");
        }
    }

    public function test_daily_sales_report_filters_paid_sales_and_preserves_gst_totals(): void
    {
        $context = $this->makePosContext();
        $gstRate = 2.5;
        Tax::query()
            ->withOutGlobalBranchPermission()
            ->withoutGlobalActive()
            ->where(fn ($query) => $query
                ->where('branch_id', $this->branch->id)
                ->orWhereNull('branch_id'))
            ->update(['is_active' => false]);
        $this->createGlobalVatTax($gstRate);

        $paidOrderIds = [];
        foreach ([100, 200] as $index => $price) {
            $cartId = (string) Str::uuid();

            $this->getJson("/api/v1/pos/viewer/{$cartId}/configuration")->assertOk();
            $this->postJson("/api/v1/cart/{$cartId}/order-types/takeaway")->assertOk();
            $this->postJson("/api/v1/cart/{$cartId}/items/quick", [
                'name' => "Report Quick Item {$index}",
                'price' => $price,
                'qty' => 1,
                'menu_id' => $context['menu']->id,
            ])->assertOk();

            $created = $this->withHeader('Idempotency-Key', 'qa-report-order-'.$index.'-'.Str::uuid())
                ->postJson("/api/v1/orders/{$cartId}", [
                    ...$this->orderPayload($context, OrderType::Takeaway->value),
                    'submit_action' => 'pay_and_fire',
                ])
                ->assertCreated();

            $order = Order::query()
                ->where('reference_no', $created->json('body.order_id'))
                ->firstOrFail();
            $dueAmount = (float) $this->getJson("/api/v1/orders/{$order->id}/payments/meta")
                ->assertOk()
                ->json('body.order.due_amount.amount');

            $this->withHeader('Idempotency-Key', 'qa-report-payment-'.$index.'-'.Str::uuid())
                ->postJson("/api/v1/orders/{$order->id}/payments", [
                    'payments' => [['method' => PaymentMethod::Cash->value, 'amount' => $dueAmount]],
                    'payment_mode' => 'full',
                    'with_print' => false,
                    'customer_given_amount' => $dueAmount,
                    'change_return' => 0,
                    'register_id' => $context['register']->id,
                    'session_id' => $context['session']->id,
                ])->assertOk();

            $paidOrderIds[] = $order->id;
        }

        Order::query()->whereKey($paidOrderIds[1])->update(['status' => OrderStatus::Cancelled]);

        $query = http_build_query([
            'filters' => [
                'group_by_date' => 'days',
                'from' => today()->toDateString(),
                'to' => today()->toDateString(),
                'payment_status' => OrderPaymentStatus::Paid->value,
            ],
        ]);
        $response = $this->getJson("/api/v1/reports/sales?{$query}")
            ->assertOk();

        $this->assertSame(1, $response->json('body.data.0.total_orders'));
        $this->assertSame(1, $response->json('body.data.0.total_products'));
        $this->assertEquals(100, (float) $response->json('body.data.0.subtotal.amount'));
        $this->assertEquals(2.5, (float) $response->json('body.data.0.tax.amount'));
        $this->assertEquals(102.5, (float) $response->json('body.data.0.total.amount'));
        $this->assertEquals(102.5, (float) $response->json('body.analytics.0.value.amount'));
        $this->assertSame(1, $response->json('body.analytics.1.value'));
    }

    public function test_processed_pos_cart_cannot_create_duplicate_order_with_new_idempotency_key(): void
    {
        $context = $this->makePosContext();
        $productId = $this->createPosProduct($context, 'Duplicate Guard Meal');
        $cartId = (string) Str::uuid();

        $this->getJson("/api/v1/pos/viewer/{$cartId}/configuration")->assertOk();
        $this->postJson("/api/v1/cart/{$cartId}/order-types/takeaway")->assertOk();
        $this->postJson("/api/v1/cart/{$cartId}/items", [
            'product_id' => $productId,
            'qty' => 1,
        ])->assertOk();

        $payload = [
            ...$this->orderPayload($context, OrderType::Takeaway->value),
            'submit_action' => 'pay_and_fire',
        ];
        $ordersBefore = Order::query()->count();

        $created = $this->withHeader('Idempotency-Key', 'qa-duplicate-guard-order-'.Str::uuid())
            ->postJson("/api/v1/orders/{$cartId}", $payload)
            ->assertCreated();

        $this->withHeader('Idempotency-Key', 'qa-duplicate-guard-order-'.Str::uuid())
            ->postJson("/api/v1/orders/{$cartId}", $payload)
            ->assertStatus(409)
            ->assertJsonPath('message', __('order::messages.cart_already_processed'));

        $this->assertSame(
            $ordersBefore + 1,
            Order::query()->count()
        );
        $this->assertTrue(Order::query()->where('reference_no', $created->json('body.order_id'))->exists());
    }

    public function test_hundred_pay_and_fire_orders_keep_totals_gst_and_no_duplicates(): void
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);

        $context = $this->makePosContext();
        $productId = $this->createPosProduct($context, 'Hundred Order GST Meal');
        $this->createGlobalVatTax();

        $referenceNumbers = [];

        for ($index = 1; $index <= 100; $index++) {
            $cartId = (string) Str::uuid();

            $this->getJson("/api/v1/pos/viewer/{$cartId}/configuration")->assertOk();
            $this->postJson("/api/v1/cart/{$cartId}/order-types/takeaway")->assertOk();
            $cartResponse = $this->postJson("/api/v1/cart/{$cartId}/items", [
                'product_id' => $productId,
                'qty' => 1,
            ])->assertOk();

            $cartTaxTotal = collect($cartResponse->json('body.taxes'))
                ->sum(fn (array $tax) => (float) data_get($tax, 'amount.amount', 0));
            $this->assertGreaterThan(0, $cartTaxTotal, "Cart {$index} should have GST before order submit.");

            $created = $this->withHeader('Idempotency-Key', 'qa-100-pay-fire-order-'.$index.'-'.Str::uuid())
                ->postJson("/api/v1/orders/{$cartId}", [
                    ...$this->orderPayload($context, OrderType::Takeaway->value),
                    'submit_action' => 'pay_and_fire',
                ])
                ->assertCreated();

            $referenceNo = $created->json('body.order_id');
            $this->assertNotContains($referenceNo, $referenceNumbers, "Order {$index} duplicated reference number.");
            $referenceNumbers[] = $referenceNo;

            $order = Order::query()
                ->where('reference_no', $referenceNo)
                ->firstOrFail();

            $meta = $this->getJson("/api/v1/orders/{$order->id}/payments/meta")
                ->assertOk();

            $metaTaxTotal = (float) $meta->json('body.order.total_tax.amount');
            $dueAmount = (float) $meta->json('body.order.due_amount.amount');
            $grandTotal = (float) $meta->json('body.order.grand_total.amount');

            $this->assertGreaterThan(0, $metaTaxTotal, "Payment meta {$index} should include GST.");
            $this->assertGreaterThan(0, $dueAmount, "Payment meta {$index} should have due amount.");
            $this->assertEqualsWithDelta($grandTotal, $dueAmount, 0.01, "Order {$index} due should match grand total before payment.");

            $this->withHeader('Idempotency-Key', 'qa-100-pay-fire-payment-'.$index.'-'.Str::uuid())
                ->postJson("/api/v1/orders/{$order->id}/payments", [
                    'payments' => [['method' => PaymentMethod::Cash->value, 'amount' => $dueAmount]],
                    'payment_mode' => 'full',
                    'with_print' => false,
                    'customer_given_amount' => $dueAmount,
                    'change_return' => 0,
                    'register_id' => $context['register']->id,
                    'session_id' => $context['session']->id,
                ])->assertOk();

            $shown = $this->getJson("/api/v1/orders/{$order->id}/show")->assertOk();
            $shownTaxTotal = collect($shown->json('body.taxes'))
                ->sum(fn (array $tax) => (float) data_get($tax, 'amount.original.amount', 0));

            $this->assertGreaterThan(0, $shownTaxTotal, "Paid order {$index} should keep GST.");
            $this->assertEquals(0, $shown->json('body.due_amount.original.amount'), "Paid order {$index} due should be zero.");
            $this->assertSame('paid', $shown->json('body.payment_status.id'), "Paid order {$index} should be paid.");
        }

        $this->assertCount(100, $referenceNumbers);
        $this->assertSame(100, collect($referenceNumbers)->unique()->count());
        $this->assertSame(100, Order::query()->whereIn('reference_no', $referenceNumbers)->count());
    }

    public function test_pay_and_fire_with_print_creates_agent_deliverable_print_job(): void
    {
        $this->app->bind(PrintRenderServiceInterface::class, fn () => new class implements PrintRenderServiceInterface {
            public function renderToBase64(
                PrintContentType $type,
                array $payload,
                PrinterPaperSize $paperSize = PrinterPaperSize::Paper80mm
            ): string {
                return base64_encode('QA image payload '.$type->value);
            }

            public function renderToEscPosBase64(
                PrintContentType $type,
                array $payload,
                PrinterPaperSize $paperSize = PrinterPaperSize::Paper80mm
            ): string {
                return base64_encode("\x1B\x40QA {$type->value} receipt\x1D\x56\x00");
            }

            public function renderToImage(
                PrintContentType $type,
                array $payload,
                PrinterPaperSize $paperSize = PrinterPaperSize::Paper80mm
            ): string {
                return 'QA image payload '.$type->value;
            }

            public function renderToHtml(
                PrintContentType $type,
                array $payload,
                PrinterPaperSize $paperSize = PrinterPaperSize::Paper80mm
            ): string {
                return '<html><body>QA '.$type->value.'</body></html>';
            }

            public function build(
                PrintContentType $type,
                array $payload,
                PrinterPaperSize $paperSize = PrinterPaperSize::Paper80mm
            ): View {
                throw new RuntimeException('The fake print renderer does not build views.');
            }
        });

        $context = $this->makePosContext();
        $productId = $this->createPosProduct($context, 'Pay Fire Printable Meal');
        $this->createGlobalVatTax();

        $agent = PrintAgent::query()->create([
            'agent_id' => 'qa-print-agent-'.Str::uuid(),
            'name' => ['en' => 'QA Print Agent', 'ar' => 'وكيل طباعة'],
            'branch_id' => $this->branch->id,
            'platform' => 'windows',
            'is_active' => true,
        ]);
        $printer = Printer::query()->create([
            'branch_id' => $this->branch->id,
            'name' => ['en' => 'QA Local POS-80', 'ar' => 'طابعة اختبار'],
            'connection_type' => PrinterConnectionType::Spooler,
            'options' => [
                'agent_id' => $agent->agent_id,
                'spooler_name' => 'QA-POS-80',
                'paper_size' => '80mm',
                'raw' => true,
                'timeout_ms' => 10000,
            ],
            'is_active' => true,
        ]);
        $context['register']->update([
            'invoice_printer_id' => $printer->id,
            'bill_printer_id' => $printer->id,
        ]);
        $kitchen = User::factory()->create([
            'branch_id' => $this->branch->id,
            'is_active' => true,
        ]);
        $kitchen->syncRoles([DefaultRole::Kitchen->value]);
        $kitchen->forceFill([
            'printer_id' => $printer->id,
            'category_slugs' => null,
        ])->saveQuietly();
        Cache::flush();

        $cartId = (string) Str::uuid();
        $this->getJson("/api/v1/pos/viewer/{$cartId}/configuration")->assertOk();
        $this->postJson("/api/v1/cart/{$cartId}/order-types/takeaway")->assertOk();
        $this->postJson("/api/v1/cart/{$cartId}/items", [
            'product_id' => $productId,
            'qty' => 1,
        ])->assertOk();

        $created = $this->withHeader('Idempotency-Key', 'qa-print-pay-fire-order-'.Str::uuid())
            ->postJson("/api/v1/orders/{$cartId}", [
                ...$this->orderPayload($context, OrderType::Takeaway->value),
                'submit_action' => 'pay_and_fire',
            ])
            ->assertCreated();

        $order = Order::query()
            ->where('reference_no', $created->json('body.order_id'))
            ->firstOrFail();
        $unrelatedOrder = Order::query()->whereKeyNot($order->id)->latest('id')->first();
        $unrelatedUpdatedAt = $unrelatedOrder?->updated_at?->toISOString();
        $dueAmount = (float) $this->getJson("/api/v1/orders/{$order->id}/payments/meta")
            ->assertOk()
            ->json('body.order.due_amount.amount');

        setting(['kitchen_print_with_payment_enabled' => true]);
        app(\Modules\Setting\Services\Setting\SettingServiceInterface::class)->refreshSettingBinding();

        $this->withHeader('Idempotency-Key', 'qa-print-pay-fire-payment-'.Str::uuid())
            ->postJson("/api/v1/orders/{$order->id}/payments", [
                'payments' => [['method' => PaymentMethod::Cash->value, 'amount' => $dueAmount]],
                'payment_mode' => 'full',
                'with_print' => true,
                'customer_given_amount' => $dueAmount,
                'change_return' => 0,
                'register_id' => $context['register']->id,
                'session_id' => $context['session']->id,
            ])->assertOk();

        app(PrintDispatcherServiceInterface::class)
            ->dispatch($order->fresh(), PrintContentType::Invoice, $context['register']->id);
        app(PrintDispatcherServiceInterface::class)
            ->dispatchKitchens($order->fresh());

        $printJob = PrintJob::query()
            ->where('branch_id', $this->branch->id)
            ->where('rendered_bytes', base64_encode("\x1B\x40QA invoice receipt\x1D\x56\x00"))
            ->latest()
            ->firstOrFail();
        $kitchenPrintJob = PrintJob::query()
            ->where('branch_id', $this->branch->id)
            ->where('rendered_bytes', base64_encode("\x1B\x40QA kitchen receipt\x1D\x56\x00"))
            ->latest()
            ->firstOrFail();

        $this->assertSame(PrintJobStatus::Pending, $kitchenPrintJob->status);
        $this->assertSame(PrintJobStatus::Pending, $printJob->status);
        $this->assertSame('spooler', data_get($printJob->printer_config, 'type'));
        $this->assertSame($agent->agent_id, data_get($printJob->printer_config, 'agent_id'));
        $this->assertSame('QA-POS-80', data_get($printJob->printer_config, 'connection.name'));

        $decodedBytes = base64_decode($printJob->rendered_bytes, true);
        $this->assertIsString($decodedBytes);
        $this->assertStringStartsWith("\x1B\x40", $decodedBytes);
        $this->assertStringContainsString('invoice receipt', $decodedBytes);
        $this->assertStringEndsWith("\x1D\x56\x00", $decodedBytes);

        $polledJobs = app(AgentPollServiceInterface::class)->poll($agent, $this->branch->id);
        $this->assertGreaterThanOrEqual(2, $polledJobs->count());
        $this->assertContains($printJob->id, $polledJobs->pluck('job_id')->all());
        $this->assertContains($kitchenPrintJob->id, $polledJobs->pluck('job_id')->all());

        app(AgentPollServiceInterface::class)->report($agent, $printJob->id, PrintJobStatus::Success);
        app(AgentPollServiceInterface::class)->report($agent, $kitchenPrintJob->id, PrintJobStatus::Success);
        $this->assertSame(PrintJobStatus::Success, $printJob->fresh()->status);
        $this->assertSame(PrintJobStatus::Success, $kitchenPrintJob->fresh()->status);
    }

    public function test_send_to_kitchen_api_creates_agent_deliverable_kitchen_print_job(): void
    {
        $this->app->bind(PrintRenderServiceInterface::class, fn () => new class implements PrintRenderServiceInterface {
            public function renderToBase64(
                PrintContentType $type,
                array $payload,
                PrinterPaperSize $paperSize = PrinterPaperSize::Paper80mm
            ): string {
                return base64_encode('QA image payload '.$type->value);
            }

            public function renderToEscPosBase64(
                PrintContentType $type,
                array $payload,
                PrinterPaperSize $paperSize = PrinterPaperSize::Paper80mm
            ): string {
                return base64_encode("\x1B\x40QA {$type->value} receipt\x1D\x56\x00");
            }

            public function renderToImage(
                PrintContentType $type,
                array $payload,
                PrinterPaperSize $paperSize = PrinterPaperSize::Paper80mm
            ): string {
                return 'QA image payload '.$type->value;
            }

            public function renderToHtml(
                PrintContentType $type,
                array $payload,
                PrinterPaperSize $paperSize = PrinterPaperSize::Paper80mm
            ): string {
                return '<html><body>QA '.$type->value.'</body></html>';
            }

            public function build(
                PrintContentType $type,
                array $payload,
                PrinterPaperSize $paperSize = PrinterPaperSize::Paper80mm
            ): View {
                throw new RuntimeException('The fake print renderer does not build views.');
            }
        });

        $context = $this->makePosContext();
        $productId = $this->createPosProduct($context, 'Kitchen Printable Meal');
        setting(['default_currency' => 'INR']);
        app(\Modules\Setting\Services\Setting\SettingServiceInterface::class)->refreshSettingBinding();

        $agent = PrintAgent::query()->create([
            'agent_id' => 'qa-kitchen-print-agent-'.Str::uuid(),
            'name' => ['en' => 'QA Kitchen Print Agent', 'ar' => 'وكيل مطبخ اختبار'],
            'branch_id' => $this->branch->id,
            'platform' => 'windows',
            'is_active' => true,
        ]);
        $printer = Printer::query()->create([
            'branch_id' => $this->branch->id,
            'name' => ['en' => 'QA Kitchen POS-80', 'ar' => 'طابعة مطبخ اختبار'],
            'connection_type' => PrinterConnectionType::Spooler,
            'options' => [
                'agent_id' => $agent->agent_id,
                'spooler_name' => 'QA-KITCHEN-80',
                'paper_size' => '80mm',
                'raw' => true,
                'timeout_ms' => 10000,
            ],
            'is_active' => true,
        ]);
        $kitchen = User::factory()->create([
            'branch_id' => $this->branch->id,
            'is_active' => true,
        ]);
        $kitchen->syncRoles([DefaultRole::Kitchen->value]);
        $kitchen->forceFill([
            'printer_id' => $printer->id,
            'category_slugs' => null,
        ])->saveQuietly();
        Cache::flush();

        $cartId = (string) Str::uuid();
        $this->getJson("/api/v1/pos/viewer/{$cartId}/configuration")->assertOk();
        $this->postJson("/api/v1/cart/{$cartId}/order-types/dine_in", [
            'table_id' => $context['table']->id,
        ])->assertOk();
        $this->postJson("/api/v1/cart/{$cartId}/items", [
            'product_id' => $productId,
            'qty' => 1,
            'table_id' => $context['table']->id,
        ])->assertOk();

        $created = $this->withHeader('Idempotency-Key', 'qa-kitchen-print-order-'.Str::uuid())
            ->postJson("/api/v1/orders/{$cartId}", [
                ...$this->orderPayload($context, OrderType::DineIn->value),
                'submit_action' => 'send_to_kitchen',
                'table_id' => $context['table']->id,
            ])
            ->assertCreated();

        $order = Order::query()
            ->where('reference_no', $created->json('body.order_id'))
            ->firstOrFail();
        $this->assertSame(OrderStatus::Confirmed, $order->status);

        $kitchenFactory = new \Modules\Printer\Factories\PrintContents\PrintKitchenContentFactory();
        $order->load($kitchenFactory->relations());
        $kitchenPayload = $kitchenFactory->resource($order);
        $this->assertNotEmpty($kitchenPayload['kitchens']);
        $this->assertNotEmpty($kitchenPayload['kitchens'][$kitchen->id]['products'] ?? []);
        $this->assertNotEmpty($kitchenFactory->printers(array_keys($kitchenPayload['kitchens'])));
        $this->assertNotEmpty($kitchenFactory->printers($kitchen->id));

        app(PrintDispatcherServiceInterface::class)->dispatchKitchens($order->fresh());

        $printJob = PrintJob::query()
            ->withoutGlobalScopes()
            ->where('printer_config->agent_id', $agent->agent_id)
            ->latest()
            ->firstOrFail();
        $this->assertSame(PrintJobStatus::Pending, $printJob->status);
        $this->assertSame('spooler', data_get($printJob->printer_config, 'type'));
        $this->assertSame($agent->agent_id, data_get($printJob->printer_config, 'agent_id'));
        $this->assertSame('QA-KITCHEN-80', data_get($printJob->printer_config, 'connection.name'));

        $decodedBytes = base64_decode($printJob->rendered_bytes, true);
        $this->assertIsString($decodedBytes);
        $this->assertStringStartsWith("\x1B\x40", $decodedBytes);
        $this->assertStringContainsString('kitchen receipt', $decodedBytes);
        $this->assertStringEndsWith("\x1D\x56\x00", $decodedBytes);

        $polledJobs = app(AgentPollServiceInterface::class)->poll($agent, $this->branch->id);
        $this->assertCount(1, $polledJobs);
        $this->assertSame($printJob->id, $polledJobs->first()['job_id']);

        app(AgentPollServiceInterface::class)->report($agent, $printJob->id, PrintJobStatus::Success);
        $this->assertSame(PrintJobStatus::Success, $printJob->fresh()->status);
    }

    public function test_send_to_kitchen_print_type_assignment_prints_without_kitchen_user_rows(): void
    {
        $this->app->bind(PrintRenderServiceInterface::class, fn () => new class implements PrintRenderServiceInterface {
            public function renderToBase64(
                PrintContentType $type,
                array $payload,
                PrinterPaperSize $paperSize = PrinterPaperSize::Paper80mm
            ): string {
                return base64_encode('QA image payload '.$type->value);
            }

            public function renderToEscPosBase64(
                PrintContentType $type,
                array $payload,
                PrinterPaperSize $paperSize = PrinterPaperSize::Paper80mm
            ): string {
                return base64_encode("\x1B\x40QA {$type->value} assigned kitchen receipt\x1D\x56\x00");
            }

            public function renderToImage(
                PrintContentType $type,
                array $payload,
                PrinterPaperSize $paperSize = PrinterPaperSize::Paper80mm
            ): string {
                return 'QA image payload '.$type->value;
            }

            public function renderToHtml(
                PrintContentType $type,
                array $payload,
                PrinterPaperSize $paperSize = PrinterPaperSize::Paper80mm
            ): string {
                return '<html><body>QA '.$type->value.'</body></html>';
            }

            public function build(
                PrintContentType $type,
                array $payload,
                PrinterPaperSize $paperSize = PrinterPaperSize::Paper80mm
            ): View {
                throw new RuntimeException('The fake print renderer does not build views.');
            }
        });

        $context = $this->makePosContext();
        $productId = $this->createPosProduct($context, 'Assigned Kitchen Printable Meal');
        setting(['default_currency' => 'INR']);
        app(\Modules\Setting\Services\Setting\SettingServiceInterface::class)->refreshSettingBinding();

        $agent = PrintAgent::query()->create([
            'agent_id' => 'qa-assigned-kitchen-agent-'.Str::uuid(),
            'name' => ['en' => 'QA Assigned Kitchen Agent', 'ar' => 'وكيل مطبخ محدد اختبار'],
            'branch_id' => $this->branch->id,
            'platform' => 'windows',
            'is_active' => true,
        ]);
        $printer = Printer::query()->create([
            'branch_id' => $this->branch->id,
            'name' => ['en' => 'QA Assigned Kitchen TCP', 'ar' => 'طابعة مطبخ محددة اختبار'],
            'connection_type' => PrinterConnectionType::Tcp,
            'options' => [
                'agent_id' => $agent->agent_id,
                'host' => '192.0.2.80',
                'port' => 9100,
                'paper_size' => '80mm',
                'timeout_ms' => 10000,
            ],
            'is_active' => true,
        ]);
        PrinterAssignment::query()->create([
            'branch_id' => $this->branch->id,
            'scope' => 'print_type',
            'print_type' => PrintContentType::Kitchen->value,
            'printer_id' => $printer->id,
        ]);
        Cache::flush();

        $cartId = (string) Str::uuid();
        $this->getJson("/api/v1/pos/viewer/{$cartId}/configuration")->assertOk();
        $this->postJson("/api/v1/cart/{$cartId}/order-types/dine_in", [
            'table_id' => $context['table']->id,
        ])->assertOk();
        $this->postJson("/api/v1/cart/{$cartId}/items", [
            'product_id' => $productId,
            'qty' => 1,
            'table_id' => $context['table']->id,
        ])->assertOk();

        $created = $this->withHeader('Idempotency-Key', 'qa-assigned-kitchen-order-'.Str::uuid())
            ->postJson("/api/v1/orders/{$cartId}", [
                ...$this->orderPayload($context, OrderType::DineIn->value),
                'submit_action' => 'send_to_kitchen',
                'table_id' => $context['table']->id,
            ])
            ->assertCreated();

        $order = Order::query()
            ->where('reference_no', $created->json('body.order_id'))
            ->firstOrFail();

        app(PrintDispatcherServiceInterface::class)->dispatchKitchens($order->fresh());

        $printJob = PrintJob::query()
            ->withoutGlobalScopes()
            ->where('printer_config->agent_id', $agent->agent_id)
            ->latest()
            ->firstOrFail();

        $this->assertSame(PrintJobStatus::Pending, $printJob->status);
        $this->assertSame('tcp', data_get($printJob->printer_config, 'type'));
        $this->assertSame('192.0.2.80', data_get($printJob->printer_config, 'connection.host'));
        $this->assertStringContainsString(
            'assigned kitchen receipt',
            base64_decode($printJob->rendered_bytes, true)
        );
    }

    public function test_dine_in_partial_payment_stays_open_and_idempotent_when_order_is_ready_to_close(): void
    {
        $context = $this->makePosContext();
        $productId = $this->createPosProduct($context, 'Partial Payment Dine In Meal');
        $cartId = (string) Str::uuid();

        $this->getJson("/api/v1/pos/viewer/{$cartId}/configuration")->assertOk();
        $this->postJson("/api/v1/cart/{$cartId}/order-types/dine_in", [
            'table_id' => $context['table']->id,
        ])->assertOk();
        $this->postJson("/api/v1/cart/{$cartId}/items", [
            'product_id' => $productId,
            'qty' => 1,
            'table_id' => $context['table']->id,
        ])->assertOk();

        $created = $this->withHeaders(['Idempotency-Key' => 'qa-dine-order-'.Str::uuid()])
            ->postJson("/api/v1/orders/{$cartId}", [
                ...$this->orderPayload($context, OrderType::DineIn->value),
                'table_id' => $context['table']->id,
            ])->assertCreated();

        $order = Order::query()
            ->where('reference_no', $created->json('body.order_id'))
            ->firstOrFail();
        $order->update(['status' => OrderStatus::Served]);

        $paymentHeaders = ['Idempotency-Key' => 'qa-dine-partial-payment-'.Str::uuid()];
        $paymentPayload = [
            'payments' => [['method' => PaymentMethod::Card->value, 'amount' => 10]],
            'payment_mode' => 'partial',
            'with_print' => false,
            'register_id' => $context['register']->id,
            'session_id' => $context['session']->id,
        ];

        $this->withHeaders($paymentHeaders)
            ->postJson("/api/v1/orders/{$order->id}/payments", $paymentPayload)
            ->assertOk();
        $this->withHeaders($paymentHeaders)
            ->postJson("/api/v1/orders/{$order->id}/payments", $paymentPayload)
            ->assertOk()
            ->assertHeader('X-NexDine-Idempotent-Replay', '1');

        $order->refresh();
        $this->assertSame(OrderStatus::Served, $order->status);
        $this->assertSame(OrderPaymentStatus::PartiallyPaid, $order->payment_status);
        $this->assertGreaterThan(0, $order->due_amount->amount());
        $this->assertSame(1, $order->payments()->count());
    }

    public function test_edit_order_keeps_existing_lines_stable_and_removes_preparing_items_cleanly(): void
    {
        setting(['default_currency' => 'INR']);
        app(\Modules\Setting\Services\Setting\SettingServiceInterface::class)->refreshSettingBinding();

        $context = $this->makePosContext();
        $keptProductId = $this->createPosProduct($context, 'Edit Keep Meal');
        $removedProductId = $this->createPosProduct($context, 'Edit Remove Meal');
        $addedProductId = $this->createPosProduct($context, 'Edit Added Meal');
        $cartId = (string) Str::uuid();

        $this->getJson("/api/v1/pos/viewer/{$cartId}/configuration")->assertOk();
        $this->postJson("/api/v1/cart/{$cartId}/order-types/dine_in", [
            'table_id' => $context['table']->id,
        ])->assertOk();
        $this->postJson("/api/v1/cart/{$cartId}/items/batch", [
            'table_id' => $context['table']->id,
            'items' => [
                ['product_id' => $keptProductId, 'qty' => 2],
                ['product_id' => $removedProductId, 'qty' => 1],
            ],
        ])->assertOk();

        $created = $this->withHeader('Idempotency-Key', 'qa-edit-order-create-'.Str::uuid())
            ->postJson("/api/v1/orders/{$cartId}", [
                ...$this->orderPayload($context, OrderType::DineIn->value),
                'submit_action' => 'send_to_kitchen',
                'table_id' => $context['table']->id,
            ])
            ->assertCreated();

        $order = Order::query()
            ->where('reference_no', $created->json('body.order_id'))
            ->firstOrFail();
        $unrelatedOrder = Order::query()->whereKeyNot($order->id)->latest('id')->first();
        $unrelatedUpdatedAt = $unrelatedOrder?->updated_at?->toISOString();

        $removedOrderProduct = $order->products()->where('product_id', $removedProductId)->firstOrFail();
        $removedOrderProduct->update(['status' => OrderProductStatus::Preparing]);

        $edit = $this->getJson("/api/v1/orders/{$cartId}/{$order->id}/edit")
            ->assertOk();

        $cartItems = collect($edit->json('body.cart.items'));
        $keptCartItem = $cartItems->first(fn (array $item) => (int) data_get($item, 'item.id') === $keptProductId);
        $removedCartItem = $cartItems->first(fn (array $item) => (int) data_get($item, 'item.id') === $removedProductId);

        $this->assertNotNull($keptCartItem);
        $this->assertNotNull($removedCartItem);
        $this->assertSame(2, (int) $keptCartItem['qty']);

        $this->putJson("/api/v1/cart/{$cartId}/items/{$keptCartItem['id']}", [
            'qty' => 3,
        ])->assertOk();
        $afterDelete = $this->deleteJson("/api/v1/cart/{$cartId}/items/{$removedCartItem['id']}")
            ->assertOk();
        $afterAdd = $this->postJson("/api/v1/cart/{$cartId}/items", [
            'product_id' => $addedProductId,
            'qty' => 1,
            'table_id' => $context['table']->id,
        ])->assertOk();
        $this->assertCount(1, $afterDelete->json('body.items'));
        $this->assertCount(2, $afterAdd->json('body.items'));
        $this->assertSame(400, (int) $afterAdd->json('body.subTotal.amount'));
        $cartTotal = (float) $afterAdd->json('body.total.amount');
        $this->assertGreaterThanOrEqual(400, $cartTotal);

        $this->withHeader('Idempotency-Key', 'qa-edit-order-update-'.Str::uuid())
            ->putJson("/api/v1/orders/{$cartId}/{$order->id}/update", [
                ...$this->orderPayload($context, OrderType::DineIn->value),
                'submit_action' => 'send_to_kitchen',
                'table_id' => $context['table']->id,
            ])
            ->assertOk();

        $order->refresh();
        if ($unrelatedOrder) {
            $this->assertSame(
                $unrelatedUpdatedAt,
                $unrelatedOrder->fresh()->updated_at?->toISOString(),
                'Editing one order must not mutate another order timestamp.'
            );
        }
        $this->assertEqualsWithDelta($cartTotal, $order->total->amount(), 0.01);
        $this->assertSame(3, (int) $order->products()->where('product_id', $keptProductId)->firstOrFail()->quantity);
        $this->assertSame(1, (int) $order->products()->where('product_id', $addedProductId)->firstOrFail()->quantity);
        $this->assertSame(
            OrderProductStatus::Cancelled,
            $order->products()->where('product_id', $removedProductId)->firstOrFail()->status
        );
        $productsSnapshot = $order->products()
            ->get()
            ->map(fn ($product) => [
                'product_id' => $product->product_id,
                'quantity' => $product->quantity,
                'status' => $product->status->value,
                'total' => $product->total->amount(),
            ])
            ->values();
        $this->assertEqualsWithDelta(
            $productsSnapshot
                ->filter(fn ($product) => ! in_array($product['status'], [OrderProductStatus::Cancelled->value, OrderProductStatus::Refunded->value], true))
                ->sum(fn ($product) => $product['total']),
            $order->subtotal->amount(),
            0.01,
            $productsSnapshot->toJson()
        );
    }

    public function test_three_dine_in_orders_pay_directly_when_waiter_status_flow_is_disabled(): void
    {
        $this->app->bind(PrintRenderServiceInterface::class, fn () => new class implements PrintRenderServiceInterface {
            public function renderToBase64(
                PrintContentType $type,
                array $payload,
                PrinterPaperSize $paperSize = PrinterPaperSize::Paper80mm
            ): string {
                return base64_encode('QA image payload '.$type->value);
            }

            public function renderToEscPosBase64(
                PrintContentType $type,
                array $payload,
                PrinterPaperSize $paperSize = PrinterPaperSize::Paper80mm
            ): string {
                return base64_encode("\x1B\x40QA {$type->value} receipt\x1D\x56\x00");
            }

            public function renderToImage(
                PrintContentType $type,
                array $payload,
                PrinterPaperSize $paperSize = PrinterPaperSize::Paper80mm
            ): string {
                return 'QA image payload '.$type->value;
            }

            public function renderToHtml(
                PrintContentType $type,
                array $payload,
                PrinterPaperSize $paperSize = PrinterPaperSize::Paper80mm
            ): string {
                return '<html><body>QA '.$type->value.'</body></html>';
            }

            public function build(
                PrintContentType $type,
                array $payload,
                PrinterPaperSize $paperSize = PrinterPaperSize::Paper80mm
            ): View {
                throw new RuntimeException('The fake print renderer does not build views.');
            }
        });

        $context = $this->makePosContext();
        $productId = $this->createPosProduct($context, 'Direct Pay Dine In Meal');

        $agent = PrintAgent::query()->create([
            'agent_id' => 'qa-direct-pay-agent-'.Str::uuid(),
            'name' => ['en' => 'QA Direct Pay Agent', 'ar' => 'وكيل دفع اختبار'],
            'branch_id' => $this->branch->id,
            'platform' => 'windows',
            'is_active' => true,
        ]);
        $printer = Printer::query()->create([
            'branch_id' => $this->branch->id,
            'name' => ['en' => 'QA Direct Pay POS-80', 'ar' => 'طابعة دفع اختبار'],
            'connection_type' => PrinterConnectionType::Spooler,
            'options' => [
                'agent_id' => $agent->agent_id,
                'spooler_name' => 'QA-DIRECT-PAY-80',
                'paper_size' => '80mm',
                'raw' => true,
                'timeout_ms' => 10000,
            ],
            'is_active' => true,
        ]);
        $context['register']->update([
            'invoice_printer_id' => $printer->id,
            'bill_printer_id' => $printer->id,
        ]);

        $kitchen = User::factory()->create([
            'branch_id' => $this->branch->id,
            'is_active' => true,
        ]);
        $kitchen->syncRoles([DefaultRole::Kitchen->value]);
        $kitchen->forceFill([
            'printer_id' => $printer->id,
            'category_slugs' => null,
        ])->saveQuietly();
        Cache::flush();
        setting([
            'waiter_table_status_flow_enabled' => false,
            'kitchen_print_with_payment_enabled' => true,
            'default_currency' => 'INR',
        ]);
        app(\Modules\Setting\Services\Setting\SettingServiceInterface::class)->refreshSettingBinding();

        for ($index = 1; $index <= 3; $index++) {
            $cartId = (string) Str::uuid();

            $this->getJson("/api/v1/pos/viewer/{$cartId}/configuration")->assertOk();
            $this->postJson("/api/v1/cart/{$cartId}/order-types/dine_in", [
                'table_id' => $context['table']->id,
            ])->assertOk();
            $this->postJson("/api/v1/cart/{$cartId}/items", [
                'product_id' => $productId,
                'qty' => 1,
                'table_id' => $context['table']->id,
            ])->assertOk();

            $created = $this->withHeader('Idempotency-Key', "qa-direct-pay-order-{$index}-".Str::uuid())
                ->postJson("/api/v1/orders/{$cartId}", [
                    ...$this->orderPayload($context, OrderType::DineIn->value),
                    'submit_action' => 'send_to_kitchen',
                    'table_id' => $context['table']->id,
                ])
                ->assertCreated();

            $order = Order::query()
                ->where('reference_no', $created->json('body.order_id'))
                ->firstOrFail();

            $this->assertSame(OrderStatus::Confirmed, $order->status, "Order {$index} should be confirmed after kitchen send.");
            $this->assertSame(TableStatus::Occupied, $context['table']->fresh()->status, "Order {$index} should occupy the table.");

            $printCountBeforeKitchenDispatch = PrintJob::query()->where('branch_id', $this->branch->id)->count();
            app(PrintDispatcherServiceInterface::class)->dispatchKitchens($order->fresh());
            $this->assertGreaterThan(
                $printCountBeforeKitchenDispatch,
                PrintJob::query()->where('branch_id', $this->branch->id)->count(),
                "Order {$index} should have a printable kitchen payload after Send to Kitchen."
            );

            $activeOrder = collect($this->getJson('/api/v1/orders/active')
                ->assertOk()
                ->json('body.orders'))
                ->firstWhere('id', $order->id);
            $this->assertNotNull($activeOrder, "Order {$index} should be visible in active orders before payment.");
            $this->assertFalse($activeOrder['allow_update_status'], "Order {$index} should not allow waiter status movement.");
            $this->assertTrue($activeOrder['allow_receive_payment'], "Order {$index} should allow direct payment.");
            $this->assertFalse(
                data_get($activeOrder, 'action_policy.update_status.allowed'),
                "Order {$index} policy should block waiter status movement."
            );
            $this->assertTrue(
                data_get($activeOrder, 'action_policy.receive_payment.allowed'),
                "Order {$index} policy should allow direct payment."
            );

            $this->withHeader('Idempotency-Key', "qa-direct-pay-status-{$index}-".Str::uuid())
                ->patchJson("/api/v1/orders/{$order->id}/move-to-next-status")
                ->assertStatus(400);

            $dueAmount = (float) $this->getJson("/api/v1/orders/{$order->id}/payments/meta")
                ->assertOk()
                ->json('body.order.due_amount.amount');
            $this->assertGreaterThan(0, $dueAmount, "Order {$index} should have amount due before payment.");

            $this->withHeader('Idempotency-Key', "qa-direct-pay-payment-{$index}-".Str::uuid())
                ->postJson("/api/v1/orders/{$order->id}/payments", [
                    'payments' => [['method' => PaymentMethod::Cash->value, 'amount' => $dueAmount]],
                    'payment_mode' => 'full',
                    'with_print' => true,
                    'customer_given_amount' => $dueAmount,
                    'change_return' => 0,
                    'register_id' => $context['register']->id,
                    'session_id' => $context['session']->id,
                ])->assertOk();

            $order->refresh();
            $this->assertSame(OrderStatus::Completed, $order->status, "Order {$index} should complete after full payment.");
            $this->assertSame(OrderPaymentStatus::Paid, $order->payment_status, "Order {$index} should be paid.");
            $this->assertNotNull($order->closed_at, "Order {$index} session should close after payment.");
            $this->assertSame(TableStatus::Available, $context['table']->fresh()->status, "Order {$index} should release the table.");

            $printCountBeforePaymentDispatch = PrintJob::query()->where('branch_id', $this->branch->id)->count();
            app(PrintDispatcherServiceInterface::class)->dispatch($order->fresh(), PrintContentType::Invoice, $context['register']->id);
            app(PrintDispatcherServiceInterface::class)->dispatchKitchens($order->fresh());
            $this->assertGreaterThan(
                $printCountBeforePaymentDispatch,
                PrintJob::query()->where('branch_id', $this->branch->id)->count(),
                "Order {$index} should have a printable invoice after payment without requiring a duplicate KOT."
            );
        }
    }

    public function test_print_agent_reclaims_expired_claimed_jobs_without_manual_retry(): void
    {
        $agent = PrintAgent::query()->create([
            'agent_id' => 'qa-agent-'.Str::uuid(),
            'name' => ['en' => 'QA Print Agent', 'ar' => 'وكيل طباعة'],
            'branch_id' => $this->branch->id,
            'is_active' => true,
        ]);
        $job = PrintJob::query()->create([
            'branch_id' => $this->branch->id,
            'deduplication_key' => hash('sha256', 'qa-print-job-'.Str::uuid()),
            'printer_config' => [
                'type' => 'tcp',
                'provider_type' => 'windows_agent',
            ],
            'rendered_bytes' => 'qa-rendered-content',
        ]);
        $service = app(AgentPollServiceInterface::class);
        $jobService = app(PrintJobServiceInterface::class);

        $this->assertCount(1, $service->poll($agent, $this->branch->id));
        $this->assertNotNull($agent->fresh()->last_seen_at);
        $this->assertCount(0, $service->poll($agent, $this->branch->id));
        $this->assertSame(1, $jobService->summary()['awaiting_agent_report']);
        $this->assertSame(0, $jobService->summary()['ready_to_print']);

        $job->update(['lease_until' => now()->subSecond()]);
        $this->assertSame(1, $jobService->summary()['ready_to_print']);
        $this->assertSame(0, $jobService->summary()['awaiting_agent_report']);
        $this->assertCount(1, $service->poll($agent, $this->branch->id));

        $jobService->retry($job->id);
        $this->assertSame(1, $jobService->summary()['ready_to_print']);
        $this->assertCount(1, $service->poll($agent, $this->branch->id));

        $service->report($agent, $job->id, PrintJobStatus::Success);
        $this->assertCount(0, $service->poll($agent, $this->branch->id));
        $this->assertSame(PrintJobStatus::Success, $job->fresh()->status);
    }

    public function test_pos_high_volume_read_device_and_offline_queue_apis_do_not_duplicate_records(): void
    {
        $context = $this->makePosContext();
        $cartId = (string) Str::uuid();

        foreach (range(1, 40) as $index) {
            $this->createPosProduct($context, "Volume Product {$index}");
        }

        foreach (range(1, 75) as $iteration) {
            $this->getJson(
                "/api/v1/pos/viewer/{$cartId}/menu-items/{$context['menu']->id}?order_type=dine_in&table_id={$context['table']->id}"
            )
                ->assertOk()
                ->assertJsonCount(40, 'body.products');

            $this->getJson('/api/v1/tables/viewer')
                ->assertOk()
                ->assertJsonFragment(['id' => $context['table']->id]);
        }

        $productId = $this->createPosProduct($context, 'Offline Volume Product');
        $tenant = Tenant::query()->withoutGlobalScopes()->create([
            'name' => 'Terminal Fleet QA',
            'slug' => 'terminal-fleet-'.Str::lower(Str::random(10)),
            'domain' => 'terminal-fleet-'.Str::lower(Str::random(10)).'.nexdine.test',
            'is_active' => true,
        ]);
        $plan = SubscriptionPlan::query()->create([
            'name' => 'Terminal Fleet QA Plan',
            'code' => 'terminal-fleet-'.Str::lower(Str::random(10)),
            'features' => ['pos', 'pos_registers', 'waiter_app'],
            'is_active' => true,
        ]);
        TenantSubscription::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'subscription_plan_id' => $plan->id,
            'status' => 'active',
            'starts_at' => now()->subMinute(),
            'ends_at' => now()->addDay(),
        ]);
        $this->branch->forceFill(['tenant_id' => $tenant->id])->saveQuietly();
        $this->user->forceFill(['tenant_id' => $tenant->id])->saveQuietly();

        foreach (range(1, 25) as $index) {
            $deviceId = "qa-terminal-{$this->user->id}-{$index}";

            $this->postJson('/api/v1/pos/terminal-devices/heartbeat', [
                'device_id' => $deviceId,
                'name' => "Load Terminal {$index}",
                'branch_id' => $this->branch->id,
                'pos_register_id' => $context['register']->id,
                'pos_session_id' => $context['session']->id,
                'status' => 'online',
                'local_queue_count' => $index,
            ])->assertOk();

            $this->postJson('/api/v1/pos/terminal-devices/heartbeat', [
                'device_id' => $deviceId,
                'branch_id' => $this->branch->id,
                'status' => 'online',
                'local_queue_count' => 0,
            ])->assertOk();
        }

        $this->assertSame(
            25,
            PosTerminalDevice::query()->where('branch_id', $this->branch->id)->count()
        );
        $this->getJson("/api/v1/pos/terminal-devices?branch_id={$this->branch->id}&per_page=100")
            ->assertOk()
            ->assertJsonPath('meta.filtered_total', 25);

        $deviceId = "qa-offline-{$this->user->id}";
        $offlinePayload = [
            'branch_id' => $this->branch->id,
            'menu_id' => $context['menu']->id,
            'register_id' => $context['register']->id,
            'session_id' => $context['session']->id,
            'type' => OrderType::Takeaway->value,
            'device_id' => $deviceId,
            'currency' => 'INR',
            'currency_rate' => 1,
            'subtotal' => 100,
            'tax_amount' => 0,
            'total' => 100,
            'items' => [[
                'product_id' => $productId,
                'quantity' => 1,
                'unit_price' => 100,
                'subtotal' => 100,
                'tax_total' => 0,
                'total' => 100,
            ]],
        ];

        foreach (range(1, 20) as $index) {
            $headers = ['Idempotency-Key' => "qa-offline-{$this->user->id}-{$index}"];
            $firstId = $this->withHeaders($headers)
                ->postJson('/api/v1/offline-mode/orders', $offlinePayload)
                ->assertOk()
                ->json('body.order_id');

            $this->assertNotEmpty($firstId);

            $this->withHeaders($headers)
                ->postJson('/api/v1/offline-mode/orders', $offlinePayload)
                ->assertOk()
                ->assertJsonPath('body.order_id', $firstId);
        }

        $this->assertSame(
            20,
            PosOfflineOrder::query()->where('device_id', $deviceId)->count()
        );
        $this->getJson("/api/v1/offline-mode/status?device_id={$deviceId}")
            ->assertOk()
            ->assertJsonPath('body.pending_orders', 20)
            ->assertJsonPath('body.total_orders', 20);

        $this->getJson("/api/v1/pos/recovery-dashboard?branch_id={$this->branch->id}")
            ->assertOk()
            ->assertJsonPath('data.storage_ready.terminal_devices', true)
            ->assertJsonPath('data.storage_ready.offline_orders', true)
            ->assertJsonPath('data.storage_ready.payments', true)
            ->assertJsonPath('data.summary.terminal_total', 25)
            ->assertJsonPath('data.summary.offline_pending', 20)
            ->assertJsonStructure([
                'data' => [
                    'summary' => [
                        'payment_pending',
                        'payment_failed',
                        'payment_gateway_pending',
                        'payment_gateway_failed',
                    ],
                    'payments',
                ],
            ]);
    }

    public function test_pos_manager_approval_replay_creates_only_one_audit_token(): void
    {
        $context = $this->makePosContext();
        $productId = $this->createPosProduct($context, 'Approval Replay Meal');
        $cartId = (string) Str::uuid();
        $this->postJson("/api/v1/cart/{$cartId}/order-types/dine_in", [
            'table_id' => $context['table']->id,
        ])->assertOk();
        $this->postJson("/api/v1/cart/{$cartId}/items", [
            'product_id' => $productId,
            'qty' => 1,
            'table_id' => $context['table']->id,
        ])->assertOk();
        $created = $this->withHeader('Idempotency-Key', 'qa-approval-order-'.Str::uuid())
            ->postJson("/api/v1/orders/{$cartId}", [
                ...$this->orderPayload($context, OrderType::DineIn->value),
                'table_id' => $context['table']->id,
            ])->assertCreated();
        $order = Order::query()->where('reference_no', $created->json('body.order_id'))->firstOrFail();

        $this->bindCurrentBranchToTenant(['pos', 'pos_registers']);
        $this->user->syncRoles([DefaultRole::SuperAdmin->value]);

        $this->postJson('/api/v1/pos/manager-approvals/pin', [
            'pin' => '2486',
            'pin_confirmation' => '2486',
        ])->assertOk();

        $this->withHeader('X-NexDine-Device-Id', 'qa-manager-terminal')
            ->postJson('/api/v1/pos/terminal-devices/heartbeat', [
                'device_id' => 'qa-manager-terminal',
                'branch_id' => $this->branch->id,
                'pos_register_id' => $context['register']->id,
                'status' => 'online',
            ])->assertOk();

        $payload = [
            'manager_id' => $this->user->id,
            'pin' => '2486',
            'branch_id' => $this->branch->id,
            'action' => 'order.cancel',
            'resource_type' => 'order',
            'resource_id' => (string) $order->id,
            'reason' => 'Volume QA duplicate prevention',
        ];
        $headers = [
            'Idempotency-Key' => 'qa-manager-approval-'.Str::uuid(),
            'X-NexDine-Device-Id' => 'qa-manager-terminal',
        ];

        $approvalToken = $this->withHeaders($headers)
            ->postJson('/api/v1/pos/manager-approvals/approve', $payload)
            ->assertOk()
            ->json('data.approval_token');

        $this->withHeaders($headers)
            ->postJson('/api/v1/pos/manager-approvals/approve', $payload)
            ->assertOk()
            ->assertHeader('X-NexDine-Idempotent-Replay', '1')
            ->assertJsonPath('data.approval_token', $approvalToken);

        $this->assertSame(
            1,
            PosManagerApproval::query()
                ->where('resource_id', (string) $order->id)
                ->count()
        );
        $this->getJson("/api/v1/pos/manager-approvals?branch_id={$this->branch->id}")
            ->assertOk()
            ->assertJsonFragment(['resource_id' => (string) $order->id]);
    }

    public function test_order_cancel_requires_and_consumes_manager_approval(): void
    {
        $context = $this->makePosContext();
        $this->user->syncRoles([DefaultRole::SuperAdmin->value]);
        $this->user->givePermissionTo('admin.orders.cancel');

        $this->postJson('/api/v1/pos/manager-approvals/pin', [
            'pin' => '2486',
            'pin_confirmation' => '2486',
        ])->assertOk();

        $reason = Reason::query()->create([
            'name' => ['en' => 'Manager approval regression'],
            'type' => ReasonType::Cancellation,
            'is_active' => true,
        ]);
        $productId = $this->createPosProduct($context, 'Protected Cancel Meal');
        $cartId = (string) Str::uuid();

        $this->postJson("/api/v1/cart/{$cartId}/order-types/dine_in", [
            'table_id' => $context['table']->id,
        ])->assertOk();
        $this->postJson("/api/v1/cart/{$cartId}/items", [
            'product_id' => $productId,
            'qty' => 1,
            'table_id' => $context['table']->id,
        ])->assertOk();

        $created = $this->withHeader('Idempotency-Key', 'qa-protected-order-'.Str::uuid())
            ->postJson("/api/v1/orders/{$cartId}", [
                ...$this->orderPayload($context, OrderType::DineIn->value),
                'table_id' => $context['table']->id,
            ])->assertCreated();
        $order = Order::query()
            ->where('reference_no', $created->json('body.order_id'))
            ->firstOrFail();
        $this->bindCurrentBranchToTenant(['pos', 'pos_registers']);
        $cancelPayload = [
            'reason_id' => $reason->id,
            'register_id' => $context['register']->id,
            'note' => 'Manager approval enforcement test',
        ];

        $this->withHeader('Idempotency-Key', 'qa-cancel-without-pin-'.Str::uuid())
            ->postJson("/api/v1/orders/{$order->id}/cancel", $cancelPayload)
            ->assertUnprocessable()
            ->assertJsonPath('message', __('pos::pos.manager_approval_required'));
        $this->assertNotSame(OrderStatus::Cancelled, $order->fresh()->status);

        $approvalPayload = [
            'manager_id' => $this->user->id,
            'branch_id' => $this->branch->id,
            'action' => 'order.cancel',
            'resource_type' => 'order',
            'resource_id' => (string) $order->id,
            'reason' => 'Approved cancellation regression test',
        ];
        $this->withHeader('Idempotency-Key', 'qa-invalid-pin-'.Str::uuid())
            ->postJson('/api/v1/pos/manager-approvals/approve', [
                ...$approvalPayload,
                'pin' => '0000',
            ])->assertUnprocessable()
            ->assertJsonPath('message', __('pos::pos.manager_pin_invalid'));
        $this->assertSame(
            0,
            PosManagerApproval::query()
                ->where('resource_id', (string) $order->id)
                ->count()
        );

        $approvalToken = $this
            ->withHeader('Idempotency-Key', 'qa-valid-pin-'.Str::uuid())
            ->postJson('/api/v1/pos/manager-approvals/approve', [
                ...$approvalPayload,
                'pin' => '2486',
            ])->assertOk()->json('data.approval_token');

        $this->withHeader('Idempotency-Key', 'qa-cancel-with-pin-'.Str::uuid())
            ->postJson("/api/v1/orders/{$order->id}/cancel", [
                ...$cancelPayload,
                'manager_approval_token' => $approvalToken,
            ])->assertOk();

        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
        $this->assertSame(
            'consumed',
            PosManagerApproval::query()
                ->where('approval_token', $approvalToken)
                ->value('status')
        );
    }

    public function test_kitchen_cancel_api_notifies_waiter_once_when_request_is_retried(): void
    {
        Event::fake([NotificationCreated::class]);

        $context = $this->makePosContext();
        $productId = $this->createPosProduct($context, 'KDS Cancellation Product');
        $waiter = User::factory()->create([
            'branch_id' => $this->branch->id,
            'is_active' => true,
        ]);
        $waiter->syncRoles([DefaultRole::Waiter->value]);

        $cartId = (string) Str::uuid();
        $this->getJson("/api/v1/pos/viewer/{$cartId}/configuration")->assertOk();
        $this->postJson("/api/v1/cart/{$cartId}/order-types/dine_in", [
            'table_id' => $context['table']->id,
        ])->assertOk();
        $this->postJson("/api/v1/cart/{$cartId}/items", [
            'product_id' => $productId,
            'qty' => 1,
            'table_id' => $context['table']->id,
        ])->assertOk();

        $created = $this->withHeader('Idempotency-Key', 'qa-kds-order-'.Str::uuid())
            ->postJson("/api/v1/orders/{$cartId}", [
                ...$this->orderPayload($context, OrderType::DineIn->value),
                'submit_action' => 'send_to_kitchen',
                'table_id' => $context['table']->id,
                'waiter_id' => $waiter->id,
            ])
            ->assertCreated();

        $order = Order::query()->where('reference_no', $created->json('body.order_id'))->firstOrFail();
        $this->bindCurrentBranchToTenant(['pos', 'kitchen'], [$waiter]);
        $productItemId = $order->products()
            ->without(['product', 'taxes', 'options'])
            ->value('id');

        $this->getJson("/api/v1/pos/kitchen-viewer/configuration?branch_id={$this->branch->id}")
            ->assertOk();
        $this->getJson("/api/v1/pos/kitchen-viewer/orders?branch_id={$this->branch->id}")
            ->assertOk()
            ->assertJsonFragment(['id' => $order->id]);

        $headers = ['Idempotency-Key' => 'qa-kds-cancel-'.Str::uuid()];
        $payload = [
            'ids' => [$productItemId],
            'reason' => 'Delayed beyond kitchen SLA',
        ];

        $this->withHeaders($headers)
            ->postJson("/api/v1/pos/kitchen-viewer/{$order->id}/products/cancel", $payload)
            ->assertOk();
        $this->withHeaders($headers)
            ->postJson("/api/v1/pos/kitchen-viewer/{$order->id}/products/cancel", $payload)
            ->assertOk()
            ->assertHeader('X-NexDine-Idempotent-Replay', '1');

        $this->assertSame(
            1,
            Notification::query()
                ->where('target_user_id', $waiter->id)
                ->where('type', 'kitchen_item_cancel')
                ->count()
        );
        Event::assertDispatched(NotificationCreated::class, fn (NotificationCreated $event) => (
            $event->notification->target_user_id === $waiter->id
            && $event->notification->type === 'kitchen_item_cancel'
        ));
    }

    private function bindCurrentBranchToTenant(array $features, array $additionalUsers = []): Tenant
    {
        $suffix = Str::lower(Str::random(10));
        $tenant = Tenant::query()->withoutGlobalScopes()->create([
            'name' => 'Real DB Smoke Tenant',
            'slug' => "real-db-smoke-{$suffix}",
            'domain' => "real-db-smoke-{$suffix}.nexdine.test",
            'is_active' => true,
        ]);
        $plan = SubscriptionPlan::query()->create([
            'name' => 'Real DB Smoke Plan',
            'code' => "real-db-smoke-{$suffix}",
            'features' => array_values(array_unique($features)),
            'is_active' => true,
        ]);
        TenantSubscription::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'subscription_plan_id' => $plan->id,
            'status' => 'active',
            'starts_at' => now()->subMinute(),
            'ends_at' => now()->addDay(),
        ]);

        $this->branch->forceFill(['tenant_id' => $tenant->id])->saveQuietly();
        collect([$this->user, ...$additionalUsers])->each(
            fn (User $user) => $user->forceFill(['tenant_id' => $tenant->id])->saveQuietly()
        );

        return $tenant;
    }

    private function makePosContext(): array
    {
        $this->branch->update([
            'order_types' => OrderType::values(),
            'payment_methods' => PaymentMethod::values(),
            'currency' => 'INR',
        ]);

        $menu = Menu::factory()->create([
            'branch_id' => $this->branch->id,
            'name' => ['en' => 'POS QA Menu', 'ar' => 'قائمة اختبار'],
            'order_types' => OrderType::values(),
            'is_active' => true,
        ]);
        $category = Category::query()->create([
            'menu_id' => $menu->id,
            'name' => ['en' => 'POS QA Category', 'ar' => 'قسم اختبار'],
            'is_active' => true,
        ]);
        $floor = Floor::factory()->create([
            'branch_id' => $this->branch->id,
            'created_by' => $this->user->id,
            'name' => ['en' => 'Ground Floor', 'ar' => 'الطابق الأرضي'],
            'order' => 1,
            'is_active' => true,
        ]);
        $zone = Zone::factory()->create([
            'branch_id' => $this->branch->id,
            'floor_id' => $floor->id,
            'created_by' => $this->user->id,
            'name' => ['en' => 'AC Hall', 'ar' => 'قاعة مكيفة'],
            'order' => 1,
            'is_active' => true,
        ]);
        $table = Table::factory()->create([
            'branch_id' => $this->branch->id,
            'floor_id' => $floor->id,
            'zone_id' => $zone->id,
            'created_by' => $this->user->id,
            'name' => ['en' => 'QA T01', 'ar' => 'طاولة اختبار'],
            'capacity' => 4,
            'order' => 1,
            'status' => TableStatus::Available,
        ]);
        $register = PosRegister::factory()->create([
            'branch_id' => $this->branch->id,
            'created_by' => $this->user->id,
            'is_active' => true,
        ]);
        $session = PosSession::factory()->create([
            'branch_id' => $this->branch->id,
            'pos_register_id' => $register->id,
            'opened_by' => $this->user->id,
            'created_by' => $this->user->id,
            'opened_at' => now(),
            'status' => PosSessionStatus::Open->value,
        ]);

        Cache::flush();

        return compact('menu', 'category', 'floor', 'zone', 'table', 'register', 'session');
    }

    private function createPosProduct(array $context, string $name, array $prices = [], bool $withOptions = false): int
    {
        $payload = [
            'menu_id' => $context['menu']->id,
            'name' => ['en' => $name, 'ar' => 'منتج اختبار'],
            'description' => ['en' => 'POS smoke product', 'ar' => 'منتج للاختبار'],
            'sku' => 'POS-QA-'.Str::upper(Str::random(8)),
            'price' => 100,
            'special_price' => null,
            'categories' => [$context['category']->id],
            'taxes' => [],
            'product_prices' => $prices,
            'ingredients' => [],
            'is_active' => true,
            'is_recommended' => true,
            'is_best_seller' => false,
            'display_priority' => 1,
        ];

        if ($withOptions) {
            $payload['options'] = [
                [
                    'name' => ['en' => 'Portion', 'ar' => 'الحجم'],
                    'type' => 'select',
                    'is_required' => true,
                    'values' => [
                        ['label' => ['en' => 'Regular', 'ar' => 'عادي'], 'price' => 0, 'price_type' => 'fixed'],
                        ['label' => ['en' => 'Large', 'ar' => 'كبير'], 'price' => 25, 'price_type' => 'fixed'],
                    ],
                ],
                [
                    'name' => ['en' => 'Extras', 'ar' => 'اضافات'],
                    'type' => 'checkbox',
                    'is_required' => false,
                    'values' => [
                        ['label' => ['en' => 'Cheese', 'ar' => 'جبنة'], 'price' => 10, 'price_type' => 'fixed'],
                    ],
                ],
                [
                    'name' => ['en' => 'Kitchen note', 'ar' => 'ملاحظة'],
                    'type' => 'text',
                    'is_required' => false,
                    'values' => [],
                ],
            ];
        }

        return $this->postJson('/api/v1/products', $payload)
            ->assertCreated()
            ->json('body.id');
    }

    private function createGlobalVatTax(float $rate = 15): Tax
    {
        $tax = Tax::query()->create([
            'branch_id' => $this->branch->id,
            'name' => ['en' => 'POS QA VAT', 'ar' => 'ضريبة اختبار'],
            'code' => 'POS-QA-VAT-'.Str::upper(Str::random(5)),
            'rate' => $rate,
            'type' => TaxType::Exclusive,
            'compound' => false,
            'order_types' => [OrderType::Takeaway->value],
            'is_global' => true,
            'is_active' => true,
        ]);

        Cache::flush();

        return $tax;
    }

    private function orderPayload(array $context, string $type): array
    {
        return [
            'submit_action' => 'hold_order',
            'menu_id' => $context['menu']->id,
            'branch_id' => $this->branch->id,
            'type' => $type,
            'guest_count' => 1,
            'register_id' => $context['register']->id,
            'session_id' => $context['session']->id,
        ];
    }

    private function posPrice(array $products, int $id): float|int
    {
        return collect($products)->firstWhere('id', $id)['selling_price']['amount'];
    }

    private function totpCode(string $secret): string
    {
        $service = app(TotpService::class);
        $method = new \ReflectionMethod($service, 'code');

        return $method->invoke($service, $secret, (int) floor(time() / 30));
    }
}
