<?php

namespace Database\Seeders;

use App\Models\User;
use App\Services\FakeLockerService;
use Database\Factories\ItemFactory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Arr;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        User::factory()->create([
            'name' => 'Test User',
            'email' => 'user@example.com',
            'password' => bcrypt('string'),
        ]);

        $locker_list = (new FakeLockerService)->getLockerList();

        ItemFactory::new()->count(count($locker_list))->create([
            'locker_id' => Arr::random($locker_list)->id,
        ]);
    }
}
