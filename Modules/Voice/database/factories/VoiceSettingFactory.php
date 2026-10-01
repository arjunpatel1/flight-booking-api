<?php

namespace Modules\Voice\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Branch\Models\Branch;
use Modules\Voice\Models\VoiceSetting;

class VoiceSettingFactory extends Factory
{
    protected $model = VoiceSetting::class;

    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'voice_enabled' => true,
            'voice_gender' => $this->faker->randomElement(['Male', 'Female']),
            'voice_rate' => $this->faker->numberBetween(-10, 10),
            'voice_volume' => $this->faker->numberBetween(0, 100),
            'selected_device_id' => null,
            'selected_device_name' => null,
            'test_voice_enabled' => true,
            'delay_threshold_minutes' => 30,
        ];
    }
}
