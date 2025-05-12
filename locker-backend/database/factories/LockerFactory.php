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
        $locker_number = $this->faker->unique()->numberBetween(1,16);
        $lockerName = "Locker-".$locker_number;
        return [
            'name' => $lockerName,
            'unit_id' => 1,
            'coil_address' => $locker_number,
            'input_address' => $locker_number,
        ];
    }
}
