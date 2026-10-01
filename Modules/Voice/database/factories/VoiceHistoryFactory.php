<?php

namespace Modules\Voice\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Branch\Models\Branch;
use Modules\Voice\Models\VoiceHistory;

class VoiceHistoryFactory extends Factory
{
    protected $model = VoiceHistory::class;

    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'order_id' => null,
            'announcement_text' => 'New order received. Table Test.',
            'event_type' => $this->faker->randomElement(['NewOrder', 'SwiggyOrder', 'CollectOrder', 'OrderDelayed', 'TestVoice']),
            'voice_gender' => $this->faker->randomElement(['Male', 'Female']),
            'device_id' => null,
            'device_name' => null,
            'volume' => 80,
            'duration' => null,
            'success' => true,
            'error_message' => null,
        ];
    }
}
