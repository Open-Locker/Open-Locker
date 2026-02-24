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

        $testUser = User::factory()->create([
            'name' => 'Locker Test User',
            'email' => 'locker-test-user@example.com',
            'password' => bcrypt('password'),
        ]);

        $testLockerBankA = LockerBank::factory()->create([
            'name' => 'Test Locker Bank A',
        ]);
        $testLockerBankB = LockerBank::factory()->create([
            'name' => 'Test Locker Bank B',
        ]);

        $createCompartmentsForLocker = function (LockerBank $lockerBank, int $count) {
            return collect(range(1, $count))->map(function (int $index) use ($lockerBank): Compartment {
                return Compartment::factory()->for($lockerBank)->create([
                    'number' => $index,
                ]);
            });
        };

        $testCompartments = $createCompartmentsForLocker($testLockerBankA, 3)
            ->merge($createCompartmentsForLocker($testLockerBankB, 4));

        $testCompartments->each(function (Compartment $compartment) use ($testUser, $admin): void {
            Item::factory()->create(['compartment_id' => $compartment->id]);

            CompartmentAccess::factory()->create([
                'user_id' => $testUser->id,
                'compartment_id' => $compartment->id,
                'granted_by_user_id' => $admin->id,
            ]);
        });
    }
}
