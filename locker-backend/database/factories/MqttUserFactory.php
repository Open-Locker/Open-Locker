<?php

namespace Database\Factories;

use App\Models\LockerBank;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<\App\Models\MqttUser>
 */
class MqttUserFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'locker_bank_id' => LockerBank::factory(),
            'username' => $this->faker->unique()->userName(),
            'password_hash' => Hash::make('password123'),
            'enabled' => true,
            'notes' => null,
        ];
    }
}
