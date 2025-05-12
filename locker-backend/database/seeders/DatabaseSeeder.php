<?php

namespace Database\Seeders;

use App\Models\Item;
use App\Models\ItemLoan;
use App\Models\Locker;
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

        $item_and_locker_count = 9;
        $locker_with_item_count = 2;

        $items = Item::factory()->count($item_and_locker_count)->create();

        Locker::factory()->count($locker_with_item_count)->create();

        // Erstelle einige Benutzer
        $users = User::factory()->count(5)->create();

        // Erstelle einige aktive Ausleihen
        foreach ($items->take(5) as $item) {
            ItemLoan::factory()->create([
                'item_id' => $item->id,
                'user_id' => $users->random()->id,
            ]);
        }

        // Erstelle einige zurückgegebene Ausleihen
        foreach ($items->skip(5) as $item) {
            ItemLoan::factory()->returned()->create([
                'item_id' => $item->id,
                'user_id' => $users->random()->id,
            ]);
        }
    }
}
