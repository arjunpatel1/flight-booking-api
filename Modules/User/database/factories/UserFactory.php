<?php

namespace Modules\User\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Branch\Models\Branch;
use Modules\User\Enums\DefaultRole;
use Modules\User\Enums\GenderType;
use Modules\User\Models\User;

class UserFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     */
    protected $model = User::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->name,
            'username' => $this->generateUniqueUsername(),
            'email' => $this->generateUniqueEmail(),
            'gender' => $this->faker->randomElement(GenderType::values()),
            'phone_country_iso_code' => 'JO',
            'phone' => fake()->numerify('79#######'),
            'password' => 'password',
            'is_active' => $this->faker->boolean
        ];
    }

    /**
     * Get a unique username, since $this->faker->unique()->username, sometimes add duplicate data
     *
     * @return mixed
     */
    function generateUniqueUsername(): string
    {
        do {
            $username = Str::random(15);
        } while (User::withTrashed()->where('username', $username)->exists());

        return $username;
    }

    /**
     * Get a unique email, since $this->faker->unique()->email, sometimes add duplicate data
     *
     * @return mixed
     */
    function generateUniqueEmail(): string
    {
        do {
            $email = $this->faker->safeEmail();
        } while (User::withTrashed()->where('email', $email)->exists());

        return $email;
    }

    /**
     * Create users with branch
     *
     * @return $this
     */
    public function withBranch(): static
    {
        return $this->state(function () {
            return [
                'branch_id' => Branch::inRandomOrder()->first()?->id,
            ];
        });
    }

    /**
     * With random role
     *
     * @param DefaultRole[] $roles
     * @return $this
     */
    public function withRandomRole(array $roles): static
    {
        return $this->afterCreating(function ($user) use ($roles) {
            $user->assignRole((fake()->randomElement($roles))->value);
        });
    }
}

