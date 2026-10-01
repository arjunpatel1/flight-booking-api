<?php

namespace Modules\Branch\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Branch\Models\Branch;
use Modules\Order\Enums\OrderType;
use Modules\Payment\Enums\PaymentMethod;

class BranchFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     */
    protected $model = Branch::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        $orderTypes = $this->faker->randomElements(
            OrderType::values(),
            $this->faker->numberBetween(1, count(OrderType::values()))
        );

        $paymentMethods = $this->faker->randomElements(
            PaymentMethod::values(),
            $this->faker->numberBetween(1, count(PaymentMethod::values()))
        );

        return [
            'name' => [
                'en' => 'NexDine '.$this->faker->city.' Branch',
                'ar' => 'فرع فوركيفا '.$this->faker->city,
            ],
            'legal_name' => 'NexDine Company',
            'registration_number' => $this->faker->numerify('##########'),
            'vat_tin' => $this->faker->numerify('############'),
            'address_line1' => $this->faker->streetAddress,
            'address_line2' => 'Amman, Jordan',
            'city' => $this->faker->city,
            'state' => $this->faker->city,
            'postal_code' => $this->faker->postcode,
            'phone' => '+91 '.$this->faker->numerify('7#########'),
            'email' => $this->faker->unique()->safeEmail,
            'country_code' => 'IN',
            'timezone' => 'Asia/Kolkata',
            'currency' => 'INR',
            'latitude' => $this->faker->latitude(78.0, 80.0),
            'longitude' => $this->faker->longitude(36.0, 38.0),
            'order_types' => $orderTypes,
            'payment_methods' => $paymentMethods,
            'cash_difference_threshold' => $this->faker->numberBetween(0, 100),
            'is_active' => true,
            'is_main' => false,
        ];
    }
}
