<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class AccountSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $admin = User::factory()
            ->admin()
            ->create([
                'name' => 'Admin User',
                'email' => 'admin@bank.local',
            ]);

        User::factory(4)->user()->create()->each(function (User $user) {
            // everyone gets an RSD account
            Account::factory()->create([
                'user_id' => $user->id,
                'currency' => 'RSD',
                'name'  => 'Main RSD',
            ]);

            // 60% users also get a foreign-currency account
            if (fake()->boolean(60)) {
                $fx = fake()->randomElement(['EUR', 'USD', 'CHF', 'JPY']);
                Account::factory()->create([
                    'user_id' => $user->id,
                    'currency' => $fx,
                    'name' => $fx . ' Account',
                ]);
            }
        });
    }
}
