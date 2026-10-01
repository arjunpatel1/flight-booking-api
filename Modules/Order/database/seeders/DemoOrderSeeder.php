<?php

namespace Modules\Order\Database\Seeders;

use App\NexDine;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Branch\Models\Branch;
use Modules\Order\Enums\OrderPaymentStatus;
use Modules\Order\Enums\OrderProductStatus;
use Modules\Order\Enums\OrderStatus;
use Modules\Order\Enums\OrderType;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderProduct;
use Modules\Payment\Enums\PaymentMethod;
use Modules\Payment\Enums\PaymentType;
use Modules\Payment\Models\Payment;
use Modules\Pos\Enums\PosSessionStatus;
use Modules\Pos\Models\PosRegister;
use Modules\Pos\Models\PosSession;
use Modules\Pos\Services\KitchenStation\KitchenStationServiceInterface;
use Modules\Product\Models\Product;
use Modules\SeatingPlan\Models\Table;
use Modules\User\Enums\DefaultRole;
use Modules\User\Models\User;

class DemoOrderSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (! NexDine::seedDemoData() || Order::query()->exists()) {
            return;
        }

        $stationService = app(KitchenStationServiceInterface::class);

        Branch::query()
            ->withoutGlobalActive()
            ->orderBy('id')
            ->get()
            ->each(fn (Branch $branch) => $this->seedBranchOrders($branch, $stationService));
    }

    private function seedBranchOrders(Branch $branch, KitchenStationServiceInterface $stationService): void
    {
        $products = $this->branchProducts($branch);
        $tables = Table::query()
            ->withOutGlobalBranchPermission()
            ->withoutGlobalActive()
            ->where('branch_id', $branch->id)
            ->orderBy('id')
            ->get();
        $waiters = $this->branchUsers($branch, DefaultRole::Waiter);
        $cashiers = $this->branchUsers($branch, DefaultRole::Cashier);
        $customers = $this->customers();
        $registers = PosRegister::query()
            ->withoutGlobalActive()
            ->where('branch_id', $branch->id)
            ->orderBy('id')
            ->get();

        if ($products->isEmpty() || $registers->isEmpty()) {
            return;
        }

        $statuses = [
            OrderStatus::Pending,
            OrderStatus::Confirmed,
            OrderStatus::Preparing,
            OrderStatus::Ready,
            OrderStatus::Served,
            OrderStatus::Completed,
        ];

        foreach ($statuses as $index => $status) {
            $table = $tables->get($index % max($tables->count(), 1));
            $cashierId = $cashiers->get($index % max($cashiers->count(), 1))?->id;
            $waiterId = $waiters->get($index % max($waiters->count(), 1))?->id;
            $register = $registers->get($index % $registers->count());
            $session = $this->activeSession($register);
            $items = $products->slice(($index * 2) % max($products->count(), 1), 3)->values();

            if ($items->isEmpty()) {
                $items = $products->take(3)->values();
            }

            DB::transaction(function () use (
                $branch,
                $stationService,
                $status,
                $index,
                $table,
                $cashierId,
                $waiterId,
                $register,
                $session,
                $customers,
                $items
            ) {
                $paymentStatus = $this->paymentStatus($status);
                $createdAt = Carbon::now()->subMinutes((count(OrderStatus::cases()) - $index) * 12);
                $productsTotal = $this->createProductsTotal($items, $branch->currency, $index);

                $order = Order::query()->create([
                    'branch_id' => $branch->id,
                    'table_id' => $table?->id,
                    'waiter_id' => $waiterId,
                    'cashier_id' => $cashierId,
                    'customer_id' => $customers->get($index % max($customers->count(), 1))?->id,
                    'pos_register_id' => $register->id,
                    'pos_session_id' => $session?->id,
                    'status' => $status,
                    'type' => $this->orderType($branch, (bool) $table),
                    'payment_status' => $paymentStatus,
                    'currency' => $branch->currency,
                    'currency_rate' => 1,
                    'subtotal' => $productsTotal['subtotal'],
                    'total' => $productsTotal['total'],
                    'cost_price' => $productsTotal['cost_price'],
                    'revenue' => $productsTotal['revenue'],
                    'guest_count' => min(max(1, ($index % 6) + 1), max(1, (int) ($table?->seats ?? 6))),
                    'notes' => $this->orderNote($status),
                    'kitchen_display' => true,
                    'is_rush' => in_array($status, [OrderStatus::Pending, OrderStatus::Preparing], true) && $index % 2 === 0,
                    'order_date' => $createdAt->toDateString(),
                    'served_at' => in_array($status, [OrderStatus::Served, OrderStatus::Completed], true) ? $createdAt->copy()->addMinutes(22) : null,
                    'closed_at' => $status === OrderStatus::Completed ? $createdAt->copy()->addMinutes(45) : null,
                    'payment_at' => $paymentStatus === OrderPaymentStatus::Paid ? $createdAt->copy()->addMinutes(48) : null,
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ]);

                $orderProductIds = $this->createOrderProducts($order, $items, $status, $index);

                if ($paymentStatus === OrderPaymentStatus::Paid && Schema::hasTable('payments')) {
                    $this->createPayment($order, $cashierId);
                }

                $stationService->routeOrderProducts($orderProductIds, $branch->id);
            });
        }
    }

    private function branchProducts(Branch $branch): Collection
    {
        return Product::query()
            ->withOutGlobalBranchPermission()
            ->withoutGlobalActive()
            ->with(['categories:id,name,slug'])
            ->whereHas('menu', fn ($query) => $query->where('branch_id', $branch->id))
            ->orderByDesc('is_best_seller')
            ->orderByDesc('is_recommended')
            ->orderBy('display_priority')
            ->limit(18)
            ->get();
    }

    private function branchUsers(Branch $branch, DefaultRole $role): Collection
    {
        return User::query()
            ->withOutGlobalBranchPermission()
            ->withoutGlobalActive()
            ->where('branch_id', $branch->id)
            ->role($role->value)
            ->orderBy('id')
            ->get();
    }

    private function customers(): Collection
    {
        $customers = User::query()
            ->withOutGlobalBranchPermission()
            ->withoutGlobalActive()
            ->role(DefaultRole::Customer->value)
            ->orderBy('id')
            ->get();

        return $customers->isNotEmpty()
            ? $customers
            : User::query()->withOutGlobalBranchPermission()->withoutGlobalActive()->orderBy('id')->limit(5)->get();
    }

    private function activeSession(PosRegister $register): ?PosSession
    {
        return PosSession::query()
            ->withOutGlobalBranchPermission()
            ->where('pos_register_id', $register->id)
            ->where('status', PosSessionStatus::Open)
            ->latest('opened_at')
            ->first();
    }

    private function orderType(Branch $branch, bool $hasTable): OrderType
    {
        $types = collect($branch->order_types ?? [])->map(fn ($type) => $type instanceof OrderType ? $type->value : $type);

        if ($hasTable && $types->contains(OrderType::DineIn->value)) {
            return OrderType::DineIn;
        }

        return OrderType::tryFrom((string) $types->first()) ?? OrderType::DineIn;
    }

    private function paymentStatus(OrderStatus $status): OrderPaymentStatus
    {
        return in_array($status, [OrderStatus::Served, OrderStatus::Completed], true)
            ? OrderPaymentStatus::Paid
            : OrderPaymentStatus::Unpaid;
    }

    private function orderProductStatus(OrderStatus $status): OrderProductStatus
    {
        return match ($status) {
            OrderStatus::Preparing => OrderProductStatus::Preparing,
            OrderStatus::Ready => OrderProductStatus::Ready,
            OrderStatus::Served, OrderStatus::Completed => OrderProductStatus::Served,
            default => OrderProductStatus::Pending,
        };
    }

    private function createProductsTotal(Collection $products, string $currency, int $offset): array
    {
        $subtotal = 0;
        $costPrice = 0;

        foreach ($products as $index => $product) {
            $quantity = ($index + $offset) % 2 === 0 ? 1 : 2;
            $unitPrice = (float) $product->selling_price->amount();
            $subtotal += $unitPrice * $quantity;
            $costPrice += ($unitPrice * 0.55) * $quantity;
        }

        $subtotal = round(max($subtotal, 1), 4);
        $costPrice = round($costPrice, 4);

        return [
            'currency' => $currency,
            'subtotal' => $subtotal,
            'total' => $subtotal,
            'cost_price' => $costPrice,
            'revenue' => round($subtotal - $costPrice, 4),
        ];
    }

    private function createOrderProducts(Order $order, Collection $products, OrderStatus $status, int $offset): array
    {
        $orderProductIds = [];

        foreach ($products as $index => $product) {
            $quantity = ($index + $offset) % 2 === 0 ? 1 : 2;
            $unitPrice = (float) $product->selling_price->amount();
            $subtotal = round($unitPrice * $quantity, 4);
            $costPrice = round($subtotal * 0.55, 4);

            $orderProduct = OrderProduct::query()->create([
                'order_id' => $order->id,
                'product_id' => $product->id,
                'currency' => $order->currency,
                'currency_rate' => 1,
                'unit_price' => $unitPrice,
                'quantity' => $quantity,
                'subtotal' => $subtotal,
                'tax_total' => 0,
                'total' => $subtotal,
                'status' => $this->orderProductStatus($status),
                'cost_price' => $costPrice,
                'revenue' => round($subtotal - $costPrice, 4),
                'created_at' => $order->created_at,
                'updated_at' => $order->updated_at,
            ]);

            $orderProductIds[] = $orderProduct->id;
        }

        return $orderProductIds;
    }

    private function createPayment(Order $order, ?int $cashierId): void
    {
        Payment::query()->create([
            'branch_id' => $order->branch_id,
            'order_id' => $order->id,
            'cashier_id' => $cashierId,
            'order_reference_no' => $order->reference_no,
            'transaction_id' => "DEMO-{$order->reference_no}",
            'method' => PaymentMethod::Cash,
            'amount' => $order->total->amount(),
            'currency' => $order->currency,
            'currency_rate' => $order->currency_rate,
            'meta' => ['source' => 'demo_seed'],
            'type' => PaymentType::Payment,
            'received_at' => $order->payment_at,
            'received_by' => $cashierId,
            'created_at' => $order->payment_at ?? $order->created_at,
            'updated_at' => $order->payment_at ?? $order->updated_at,
        ]);
    }

    private function orderNote(OrderStatus $status): ?string
    {
        return match ($status) {
            OrderStatus::Pending => 'Demo order waiting for waiter confirmation.',
            OrderStatus::Preparing => 'Demo order routed to kitchen display.',
            OrderStatus::Ready => 'Demo order ready for service.',
            default => null,
        };
    }
}
