<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Factories\Factory;

class TransactionFactory extends Factory
{
    protected $model = Transaction::class;

    public function definition(): array
    {
        $account = Account::factory()->create(); // ensure we have currency to align with
        $type = $this->faker->randomElement(['debit', 'credit', 'transfer']);

        $baseAmountMinor = $this->faker->numberBetween(100, 5_000_00);
        $data = [
            'account_id' => $account->id,
            'type' => $type,
            'currency' => $account->currency,
            'description' => $this->faker->sentence(3),
            'category_id' => null,
            'counterparty_account_id' => null,
            'fx_rate'   => null,
            'fx_base'  => null,
            'fx_quote'  => null,
            'executed_at'  => $this->faker->dateTimeBetween('-90 days', 'now'),
        ];

        $signed = match ($type) {
            'debit'  => -$baseAmountMinor,
            'credit'  => +$baseAmountMinor,
            'transfer' => -$baseAmountMinor,
        };

        $data['amount_minor'] = $signed;

        if ($type === 'transfer') {
            $differentCurrency = $this->faker->boolean(60);
            $counter = $differentCurrency
                ? Account::factory()->state(function () use ($account) {
                    $others = array_values(array_diff(['RSD', 'EUR', 'USD', 'CHF', 'JPY'], [$account->currency]));
                    return ['currency' => $others[array_rand($others)]];
                })->create()
                : Account::factory()->state(['currency' => $account->currency])->create();

            $data['counterparty_account_id'] = $counter->id;

            if ($differentCurrency) {

                $data['fx_base']  = $account->currency;
                $data['fx_quote'] = $counter->currency;

                $data['fx_rate'] = match ($data['fx_base'] . '_' . $data['fx_quote']) {
                    'RSD_EUR' => $this->faker->randomFloat(6, 0.007, 0.010),
                    'EUR_RSD' => $this->faker->randomFloat(4, 110, 125),
                    'RSD_USD' => $this->faker->randomFloat(6, 0.008, 0.012),
                    'USD_RSD' => $this->faker->randomFloat(4, 95, 120),
                    'RSD_CHF' => $this->faker->randomFloat(6, 0.007, 0.010),
                    'CHF_RSD' => $this->faker->randomFloat(4, 110, 130),
                    'RSD_JPY' => $this->faker->randomFloat(6, 1.0, 1.3),
                    'JPY_RSD' => $this->faker->randomFloat(6, 0.7, 1.1),
                    default   => $this->faker->randomFloat(6, 0.5, 150),
                };
            }
        }

        return $data;
    }

    public function debit(): self
    {
        return $this->state(function (array $attrs) {
            $amount = $attrs['amount_minor'] ?? $this->faker->numberBetween(100, 2_000_00);
            return ['type' => 'debit', 'amount_minor' => -abs($amount)];
        });
    }

    public function credit(): self
    {
        return $this->state(function (array $attrs) {
            $amount = $attrs['amount_minor'] ?? $this->faker->numberBetween(100, 2_000_00);
            return ['type' => 'credit', 'amount_minor' => abs($amount)];
        });
    }

    public function transferSameCurrency(): self
    {
        return $this->state(function () {
            $src = Account::factory()->create();
            $dst = Account::factory()->state(['currency' => $src->currency])->create();

            return [
                'type' => 'transfer',
                'account_id'  => $src->id,
                'counterparty_account_id' => $dst->id,
                'currency'  => $src->currency,
                'fx_rate' => null,
                'fx_base' => null,
                'fx_quote' => null,
                'amount_minor' => -$this->faker->numberBetween(100, 3_000_00),
            ];
        });
    }

    public function transferFx(): self
    {
        return $this->state(function () {
            $src = Account::factory()->create();
            $dst = Account::factory()->state(function () use ($src) {
                $others = array_values(array_diff(['RSD', 'EUR', 'USD', 'CHF', 'JPY'], [$src->currency]));
                return ['currency' => $others[array_rand($others)]];
            })->create();

            $rate = $this->faker->randomFloat(6, 0.005, 150);

            return [
                'type'   => 'transfer',
                'account_id' => $src->id,
                'counterparty_account_id'  => $dst->id,
                'currency' => $src->currency,
                'fx_base' => $src->currency,
                'fx_quote' => $dst->currency,
                'fx_rate' => $rate,
                'amount_minor' => -$this->faker->numberBetween(100, 3_000_00),
            ];
        });
    }
}
