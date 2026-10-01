<?php

namespace Modules\Order\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Branch\Models\Branch;
use Modules\Order\Enums\OrderPaymentStatus;
use Modules\Order\Enums\OrderStatus;
use Modules\Order\Enums\OrderType;
use Modules\Order\Models\Order;

class OrderFactory extends Factory
{
    protected $model = Order::class;

    public function definition(): array
    {
        $total = $this->faker->randomFloat(2, 100, 2500);

        return [
            'branch_id' => Branch::factory(),
            'status' => OrderStatus::Pending,
            'type' => OrderType::DineIn,
            'payment_status' => OrderPaymentStatus::Unpaid,
            'currency' => 'INR',
            'currency_rate' => 1,
            'subtotal' => $total,
            'total' => $total,
            'due_amount' => $total,
            'guest_count' => $this->faker->numberBetween(1, 6),
            'order_date' => now()->toDateString(),
            'is_stock_deducted' => false,
        ];
    }
}
