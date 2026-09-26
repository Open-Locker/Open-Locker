<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\User;
use App\Support\Organizations\DefaultOrganization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    /**
     * A user always exists inside an organization: there is no such thing as a
     * user belonging to the installation. Factories place them in the default
     * one unless a test attaches its own memberships.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (User $user): void {
            if ($user->organizations()->exists()) {
                return;
            }

            $organization = Organization::query()->firstOrCreate(
                ['slug' => DefaultOrganization::SLUG],
                ['name' => 'Default Organization'],
            );

            $user->organizations()->attach($organization->id, ['joined_at' => now()]);
        });
    }

    public function definition(): array
    {
        return [
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
