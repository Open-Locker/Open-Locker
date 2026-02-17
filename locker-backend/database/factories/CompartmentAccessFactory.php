<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Compartment;
use App\Models\CompartmentAccess;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CompartmentAccess>
 */
class CompartmentAccessFactory extends Factory
{
    protected $model = CompartmentAccess::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'compartment_id' => Compartment::factory(),
            'granted_at' => now(),
            'expires_at' => null,
            'revoked_at' => null,
            'notes' => null,
        ];
    }
}
