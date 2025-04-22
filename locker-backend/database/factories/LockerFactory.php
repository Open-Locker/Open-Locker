<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Locker>
 */
class LockerFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->randomLetter().' '.$this->faker->randomNumber(3),
            'unit_id' => 1,
            'coil_address' => $this->faker->numberBetween(1, 8),
            'input_address' => $this->faker->numberBetween(1, 8),
        ];
    }
}
