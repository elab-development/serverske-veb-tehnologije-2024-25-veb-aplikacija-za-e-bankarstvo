<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TransactionSeeder extends Seeder
{
    public function run(): void
    {
        $catSalary      = Category::where('name', 'Salary')->first();
        $catGroceries   = Category::where('name', 'Groceries')->first();
        $catRestaurants = Category::where('name', 'Restaurants')->first();
        $catUtilities   = Category::where('name', 'Utilities')->first();
        $catTransport   = Category::where('name', 'Transport')->first();
        $catTransfers   = Category::where('name', 'Transfers')->first();

        Account::with('user')->get()->each(function (Account $acc) use (
            $catSalary,
            $catGroceries,
            $catRestaurants,
            $catUtilities,
            $catTransport
        ) {
            // Start everyone with a salary credit into their "main" account
            // 110,000.00 RSD equivalent in local currency; adjust for others
            $seedCredits = [
                'RSD' => 110_000_00,
                'EUR' => 1_000_00,
                'USD' => 1_100_00,
                'CHF' => 1_000_00,
                'JPY' => 150_000_00,
            ];

            $salaryMinor = $seedCredits[$acc->currency] ?? 50_000_00;

            $this->applyCredit($acc, $salaryMinor, $acc->currency, 'Monthly salary', $catSalary?->id);

            $this->applyDebit($acc, fake()->numberBetween(1_200_00, 12_000_00), $acc->currency, 'Groceries - Maxi', $catGroceries?->id);
            $this->applyDebit($acc, fake()->numberBetween(800_00,   5_000_00),  $acc->currency, 'Restaurant - Lunch', $catRestaurants?->id);
            $this->applyDebit($acc, fake()->numberBetween(3_000_00, 9_000_00),  $acc->currency, 'Utilities - Internet', $catUtilities?->id);
            $this->applyDebit($acc, fake()->numberBetween(600_00,   3_000_00),  $acc->currency, 'Transport - Bus/Taxi', $catTransport?->id);
        });

        $this->seedTransfers($catTransfers?->id);
    }

    private function applyCredit(Account $acc, int $amountMinor, string $currency, string $desc, ?int $categoryId = null): void
    {
        DB::transaction(function () use ($acc, $amountMinor, $currency, $desc, $categoryId) {
            Transaction::create([
                'account_id'  => $acc->id,
                'type'        => 'credit',
                'amount_minor' => +abs($amountMinor),
                'currency'    => $currency,
                'description' => $desc,
                'category_id' => $categoryId,
                'executed_at' => now()->subDays(fake()->numberBetween(5, 25)),
            ]);

            $acc->increment('balance_minor', $amountMinor / 100);
        });
    }

    private function applyDebit(Account $acc, int $amountMinor, string $currency, string $desc, ?int $categoryId = null): void
    {
        DB::transaction(function () use ($acc, $amountMinor, $currency, $desc, $categoryId) {
            Transaction::create([
                'account_id'  => $acc->id,
                'type'        => 'debit',
                'amount_minor' => -abs($amountMinor),
                'currency'    => $currency,
                'description' => $desc,
                'category_id' => $categoryId,
                'executed_at' => now()->subDays(fake()->numberBetween(0, 10)),
            ]);

            $acc->decrement('balance_minor', $amountMinor / 100);
        });
    }

    private function seedTransfers(?int $categoryIdTransfers): void
    {
        $candidates = Account::select('user_id')
            ->groupBy('user_id')
            ->havingRaw('COUNT(*) >= 2')
            ->pluck('user_id');

        foreach ($candidates as $userId) {
            $accounts = Account::where('user_id', $userId)->get();

            if ($accounts->count() < 2) continue;

            foreach (range(1, fake()->numberBetween(2, 3)) as $_) {
                [$src, $dst] = $accounts->random(2)->all();

                $amountMinor = fake()->numberBetween(2_000_00, 15_000_00); // 2,000.00 – 15,000.00

                if ($src->currency === $dst->currency) {
                    $this->createSameCurrencyTransfer($src, $dst, $amountMinor, $categoryIdTransfers);
                } else {
                    $this->createFxTransfer($src, $dst, $amountMinor, $categoryIdTransfers);
                }
            }
        }
    }

    private function createSameCurrencyTransfer(Account $src, Account $dst, int $amountMinor, ?int $categoryIdTransfers): void
    {
        DB::transaction(function () use ($src, $dst, $amountMinor, $categoryIdTransfers) {
            Transaction::create([
                'account_id' => $src->id,
                'type'  => 'transfer',
                'amount_minor'  => -$amountMinor,
                'currency' => $src->currency,
                'description'  => "Transfer to {$dst->name}",
                'category_id' => $categoryIdTransfers,
                'counterparty_account_id' => $dst->id,
                'executed_at' => now()->subDays(fake()->numberBetween(1, 7)),
            ]);

            Transaction::create([
                'account_id'              => $dst->id,
                'type'                    => 'transfer',
                'amount_minor'            => +$amountMinor,
                'currency'                => $dst->currency,
                'description'             => "Transfer from {$src->name}",
                'category_id'             => $categoryIdTransfers,
                'counterparty_account_id' => $src->id,
                'executed_at'             => now()->subDays(fake()->numberBetween(1, 7)),
            ]);

            $src->decrement('balance_minor', $amountMinor / 100);
            $dst->increment('balance_minor', $amountMinor / 100);
        });
    }

    private function createFxTransfer(Account $src, Account $dst, int $amountMinorSrc, ?int $categoryIdTransfers): void
    {
        $rates = [
            'RSD_EUR' => 0.0086,
            'EUR_RSD' => 116.0,
            'RSD_USD' => 0.0091,
            'USD_RSD' => 110.0,
            'RSD_CHF' => 0.0088,
            'CHF_RSD' => 114.0,
            'RSD_JPY' => 1.00,
            'JPY_RSD' => 1.00,
            'EUR_USD' => 1.08,
            'USD_EUR' => 0.93,
            'EUR_CHF' => 0.96,
            'CHF_EUR' => 1.04,
            'USD_CHF' => 0.89,
            'CHF_USD' => 1.12,
            'EUR_JPY' => 170.0,
            'JPY_EUR' => 0.0059,
            'USD_JPY' => 155.0,
            'JPY_USD' => 0.0065,
            'CHF_JPY' => 178.0,
            'JPY_CHF' => 0.0056,
        ];

        $key = "{$src->currency}_{$dst->currency}";
        $fx  = $rates[$key] ?? fake()->randomFloat(6, 0.005, 150);

        $amountDstUnits = ($amountMinorSrc / 100.0) * $fx;
        $amountMinorDst = (int) round($amountDstUnits * 100);

        DB::transaction(function () use ($src, $dst, $amountMinorSrc, $amountMinorDst, $fx, $categoryIdTransfers) {
            Transaction::create([
                'account_id' => $src->id,
                'type' => 'transfer',
                'amount_minor' => -$amountMinorSrc,
                'currency' => $src->currency,
                'description' => "FX Transfer to {$dst->name}",
                'category_id'  => $categoryIdTransfers,
                'counterparty_account_id' => $dst->id,
                'fx_rate' => $fx,
                'fx_base' => $src->currency,
                'fx_quote'  => $dst->currency,
                'executed_at'  => now()->subDays(fake()->numberBetween(2, 10)),
            ]);

            Transaction::create([
                'account_id' => $dst->id,
                'type' => 'transfer',
                'amount_minor'            => +$amountMinorDst,
                'currency' => $dst->currency,
                'description' => "FX Transfer from {$src->name}",
                'category_id' => $categoryIdTransfers,
                'counterparty_account_id' => $src->id,
                'fx_rate' => $fx,
                'fx_base' => $src->currency,
                'fx_quote'  => $dst->currency,
                'executed_at' => now()->subDays(fake()->numberBetween(2, 10)),
            ]);

            $src->decrement('balance_minor', $amountMinorSrc / 100);
            $dst->increment('balance_minor', $amountMinorDst / 100);
        });
    }
}
