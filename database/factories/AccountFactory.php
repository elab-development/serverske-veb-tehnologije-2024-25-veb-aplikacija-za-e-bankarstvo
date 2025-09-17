<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Support\Str;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Account>
 */
class AccountFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $currencies = ['RSD', 'EUR', 'USD', 'CHF', 'JPY'];
        $currency = $this->faker->randomElement($currencies);
        $number = Str::upper(
            match ($currency) {
                'RSD' => 'RS',
                'EUR' => 'EU',
                'USD' => 'US',
                'CHF' => 'CH',
                'JPY' => 'JP',
            }
        ) . $this->faker->numerify('## #### #### #### ####');
        $balance = $this->faker->randomFloat(2, 0, 500000);


        return [
            'user_id' => User::factory(),
            'number' => $number,
            'currency' => $currency,
            'balance_minor' => $balance,
            'name' => $currency . ' Account',
        ];
    }

    public function rsd(): self
    {
        return $this->state(fn() => ['currency' => 'RSD', 'name' => 'Main RSD']);
    }
    public function eur(): self
    {
        return $this->state(fn() => ['currency' => 'EUR', 'name' => 'EUR Account']);
    }
    public function usd(): self
    {
        return $this->state(fn() => ['currency' => 'USD', 'name' => 'USD Account']);
    }
    public function chf(): self
    {
        return $this->state(fn() => ['currency' => 'CHF', 'name' => 'CHF Account']);
    }
    public function jpy(): self
    {
        return $this->state(fn() => ['currency' => 'JPY', 'name' => 'JPY Account']);
    }
}
