<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\LockerAdapterType;
use App\Enums\LockerFeedbackType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\LockerBank>
 */
class LockerBankFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->company().' Branch',
            'location_description' => $this->faker->address(),
            'adapter_type' => LockerAdapterType::WaveshareModbus,
            'channel_count' => 8,
            'feedback_type' => LockerFeedbackType::DoorClosing,
        ];
    }
}
