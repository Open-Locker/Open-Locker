<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Compartment;
use App\Models\Group;
use App\Models\GroupCompartmentAccess;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GroupCompartmentAccess>
 */
class GroupCompartmentAccessFactory extends Factory
{
    protected $model = GroupCompartmentAccess::class;

    public function definition(): array
    {
        return [
            'group_id' => Group::factory(),
            'compartment_id' => Compartment::factory(),
            'granted_at' => now(),
            'expires_at' => null,
            'revoked_at' => null,
            'notes' => null,
        ];
    }
}
