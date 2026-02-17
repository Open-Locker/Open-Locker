<?php

namespace Database\Seeders;

use App\Models\Compartment;
use App\Models\CompartmentAccess;
use App\Models\Item;
use App\Models\LockerBank;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        $admin = User::factory()->create([
            'name' => 'Admin User',
            'email' => 'user@example.com',
            'password' => bcrypt('string'),
        ]);

        $admin->makeAdmin();

        // Create one Locker Bank
        $lockerBank = LockerBank::factory()->create(['name' => 'Main Locker Bank']);

        // Create compartments for the locker bank
        $compartments = Compartment::factory()->count(10)->for($lockerBank)->create();

        // Create items and assign them to compartments
        $compartments->each(function (Compartment $compartment) {
            Item::factory()->create(['compartment_id' => $compartment->id]);
        });

        // Create some users
        $users = User::factory()->count(5)->create();

        // Create compartment access grants for some users.
        foreach ($compartments->take(5) as $compartment) {
            CompartmentAccess::factory()->create([
                'compartment_id' => $compartment->id,
                'user_id' => $users->random()->id,
            ]);
        }
    }
}
