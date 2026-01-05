<?php

namespace Database\Factories;

use App\Models\Compartment;
use App\Models\LockerBank;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Compartment>
 */
class CompartmentFactory extends Factory
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
        ];
    }
}
