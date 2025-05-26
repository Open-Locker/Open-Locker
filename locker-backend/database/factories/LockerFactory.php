<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Locker>
 */
class LockerFactory extends Factory
{
    private static int $sequence = 0;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $locker_number = self::$sequence;
        $lockerName = 'Locker-'.$locker_number;
        self::$sequence++;

        return [
            'name' => $lockerName,
            'unit_id' => 1,
            'coil_address' => $locker_number,
            'input_address' => $locker_number,
        ];
    }
}
