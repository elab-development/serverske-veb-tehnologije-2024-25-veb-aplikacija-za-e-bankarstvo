<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $categories = [
            [
                'name' => 'Salary',
                'description' => 'Monthly salary and bonuses'
            ],
            [
                'name' => 'Rent',
                'description' => 'Apartment rent and housing fees'
            ],
            [
                'name' => 'Utilities',
                'description' => 'Electricity, water, heating, internet'
            ],
            [
                'name' => 'Groceries',
                'description' => 'Supermarket and food essentials'
            ],
            [
                'name' => 'Restaurants',
                'description' => 'Dining and coffee shops'
            ],
            [
                'name' => 'Transport',
                'description' => 'Public transport, fuel, taxi'
            ],
            [
                'name' => 'Entertainment',
                'description' => 'Movies, music, subscriptions'
            ],
            [
                'name' => 'Healthcare',
                'description' => 'Pharmacy and medical costs'
            ],
            [
                'name' => 'Education',
                'description' => 'Books, courses, tuition'
            ],
            [
                'name' => 'Transfers',
                'description' => 'Internal/external account transfers'
            ],
        ];

        foreach ($categories as $c) {
            Category::firstOrCreate(['name' => $c['name']], $c);
        }
    }
}
