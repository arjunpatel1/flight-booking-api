<?php

namespace Modules\Voice\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Branch\Models\Branch;
use Modules\Voice\Models\VoiceTemplate;

class VoiceTemplateFactory extends Factory
{
    protected $model = VoiceTemplate::class;

    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'template_name' => $this->faker->words(3, true),
            'template_text' => 'New order received. Table {TableNumber}.',
            'event_type' => $this->faker->randomElement(['NewOrder', 'SwiggyOrder', 'CollectOrder', 'OrderDelayed']),
            'is_default' => false,
            'priority' => $this->faker->numberBetween(0, 2),
            'is_active' => true,
        ];
    }
}
