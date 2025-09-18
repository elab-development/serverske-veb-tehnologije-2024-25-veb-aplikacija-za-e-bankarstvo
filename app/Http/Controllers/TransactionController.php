<?php

namespace App\Http\Controllers;

use App\Http\Resources\TransactionResource;
use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Services\ExchangeRateService;


class TransactionController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $auth = Auth::user();
        if (!$auth) {
            return response()->json(['error' => 'Unauthenticated.'], 401);
        }

        $user = User::query()->findOrFail($auth->id);

        $q = Transaction::query()->with(['account', 'category', 'counterpartyAccount']);

        // Scope by role
        if ($user->role !== 'admin') {
            $q->where(function ($sub) use ($user) {
                $sub->whereHas('account', fn($a) => $a->where('user_id', $user->id))
                    ->orWhereHas('counterpartyAccount', fn($a) => $a->where('user_id', $user->id));
            });
        }

        // Filters
        $q->when($request->integer('account_id'), fn($qq, $v) => $qq->where('account_id', $v));
        $q->when($request->integer('category_id'), fn($qq, $v) => $qq->where('category_id', $v));

        if ($request->filled('type')) {
            $types = array_intersect((array) $request->input('type'), ['debit', 'credit', 'transfer']);
            if ($types) $q->whereIn('type', $types);
        }

        $currs = null;
        if ($request->filled('currency')) {
            $currs = array_intersect((array) $request->input('currency'), ['RSD', 'EUR', 'USD', 'CHF', 'JPY']);
            if ($currs) $q->whereIn('currency', $currs);
        }

        if ($request->filled('date_from')) $q->whereDate('executed_at', '>=', $request->date('date_from'));
        if ($request->filled('date_to'))   $q->whereDate('executed_at', '<=', $request->date('date_to'));

        // Decide decimals for min/max based on a single selected currency (JPY=0, others=2)
        $decimalsForFilter = 2;
        if (is_array($currs) && count($currs) === 1 && reset($currs) === 'JPY') {
            $decimalsForFilter = 0;
        }

        if ($request->filled('min_amount')) {
            $q->where('amount_minor', '>=', (int) round($this->toMinor($request->input('min_amount'), $decimalsForFilter)));
        }
        if ($request->filled('max_amount')) {
            $q->where('amount_minor', '<=', (int) round($this->toMinor($request->input('max_amount'), $decimalsForFilter)));
        }

        if ($request->filled('q')) {
            $term = trim($request->input('q'));
            $q->where('description', 'LIKE', "%{$term}%");
        }

        // Sorting + pagination
        $sortBy  = in_array($request->input('sort_by'), ['executed_at', 'amount_minor', 'id']) ? $request->input('sort_by') : 'executed_at';
        $sortDir = $request->input('sort_dir') === 'asc' ? 'asc' : 'desc';
        $q->orderBy($sortBy, $sortDir);

        $perPage = min(max((int) $request->input('per_page', 15), 1), 100);
        $p = $q->paginate($perPage);

        return response()->json([
            'transactions' => TransactionResource::collection($p),
        ]);
    }


    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request, ExchangeRateService $fx)
    {
        $auth = Auth::user();
        if (!$auth) {
            return response()->json(['error' => 'Unauthenticated.'], 401);
        }
        $user = User::query()->findOrFail($auth->id);

        $rules = [
            'type' => 'required|in:debit,credit,transfer',
            'account_id' => 'required|exists:accounts,id',
            'amount' => 'required|numeric|min:0.01',
            'description' => 'nullable|string|max:255',
            'category_id' => 'nullable|exists:categories,id',
            'executed_at' => 'nullable|date',
        ];
        if ($request->input('type') === 'transfer') {
            $rules['counterparty_account_id'] = 'required|different:account_id|exists:accounts,id';
        }
        $validated = $request->validate($rules);

        $source = Account::findOrFail($validated['account_id']);

        if ($user->role !== 'admin' && $source->user_id !== $user->id) {
            return response()->json(['error' => 'Forbidden (source account not owned).'], 403);
        }

        $amountMinor = $this->toMinor($validated['amount'], $this->currencyDecimals($source->currency));

        return DB::transaction(function () use ($request, $user, $source, $amountMinor, $validated, $fx) {
            $now = now();

            // TRANSFER
            if ($validated['type'] === 'transfer') {
                $dest = Account::findOrFail($validated['counterparty_account_id']);

                // Same-currency transfer
                if ($dest->currency === $source->currency) {
                    if ($source->balance_minor < $amountMinor) {
                        return response()->json(['error' => 'Insufficient funds.'], 409);
                    }

                    $tOut = Transaction::create([
                        'account_id' => $source->id,
                        'type' => 'transfer',
                        'amount_minor'  => -$amountMinor,
                        'currency' => $source->currency,
                        'description' => $validated['description'] ?? "Transfer to {$dest->name}",
                        'category_id' => $validated['category_id'] ?? null,
                        'counterparty_account_id' => $dest->id,
                        'executed_at' => $validated['executed_at'] ?? $now,
                    ]);

                    $tIn = Transaction::create([
                        'account_id' => $dest->id,
                        'type'  => 'transfer',
                        'amount_minor' => +$amountMinor,
                        'currency' => $dest->currency,
                        'description' => $validated['description'] ?? "Transfer from {$source->name}",
                        'category_id' => $validated['category_id'] ?? null,
                        'counterparty_account_id' => $source->id,
                        'executed_at' => $validated['executed_at'] ?? $now,
                    ]);

                    $source->decrement('balance_minor', $amountMinor);
                    $dest->increment('balance_minor', $amountMinor);

                    return response()->json([
                        'message' => 'Transfer completed',
                        'out' => new TransactionResource($tOut->load(['account', 'counterpartyAccount', 'category'])),
                        'in' => new TransactionResource($tIn->load(['account', 'counterpartyAccount', 'category'])),
                    ], 201);
                }

                // Cross-currency transfer
                $rate = $fx->getRate($source->currency, $dest->currency);
                if (!$rate || $rate <= 0) {
                    return response()->json(['error' => 'Failed to fetch FX rate.'], 424);
                }

                $dstMinor = $this->convertMinor(
                    $amountMinor,
                    $this->currencyDecimals($source->currency),
                    $this->currencyDecimals($dest->currency),
                    $rate
                );

                if ($source->balance_minor < $amountMinor) {
                    return response()->json(['error' => 'Insufficient funds.'], 409);
                }

                $tOut = Transaction::create([
                    'account_id' => $source->id,
                    'type' => 'transfer',
                    'amount_minor' => -$amountMinor,
                    'currency' => $source->currency,
                    'description' => $validated['description'] ?? "FX Transfer to {$dest->name}",
                    'category_id'  => $validated['category_id'] ?? null,
                    'counterparty_account_id' => $dest->id,
                    'fx_rate' => $rate,
                    'fx_base' => $source->currency,
                    'fx_quote' => $dest->currency,
                    'executed_at' => $validated['executed_at'] ?? $now,
                ]);

                $tIn = Transaction::create([
                    'account_id' => $dest->id,
                    'type'  => 'transfer',
                    'amount_minor' => +$dstMinor,
                    'currency' => $dest->currency,
                    'description' => $validated['description'] ?? "FX Transfer from {$source->name}",
                    'category_id' => $validated['category_id'] ?? null,
                    'counterparty_account_id' => $source->id,
                    'fx_rate' => $rate,
                    'fx_base' => $source->currency,
                    'fx_quote' => $dest->currency,
                    'executed_at' => $validated['executed_at'] ?? $now,
                ]);

                $source->decrement('balance_minor', $amountMinor);
                $dest->increment('balance_minor', $dstMinor);

                return response()->json([
                    'message' => 'FX transfer completed',
                    'rate'    => $rate,
                    'out'     => new TransactionResource($tOut->load(['account', 'counterpartyAccount', 'category'])),
                    'in'      => new TransactionResource($tIn->load(['account', 'counterpartyAccount', 'category'])),
                ], 201);
            }

            // CREDIT/DEBIT (admin only)
            if ($user->role !== 'admin') {
                return response()->json(['error' => 'Only admins can create credits/debits directly.'], 403);
            }

            if ($validated['type'] === 'debit' && $source->balance_minor < $amountMinor) {
                return response()->json(['error' => 'Insufficient funds.'], 409);
            }

            $signed = $validated['type'] === 'debit' ? -$amountMinor : +$amountMinor;

            $txn = Transaction::create([
                'account_id' => $source->id,
                'type' => $validated['type'],
                'amount_minor' => $signed,
                'currency' => $source->currency,
                'description' => $validated['description'] ?? ucfirst($validated['type']),
                'category_id' => $validated['category_id'] ?? null,
                'executed_at' => $validated['executed_at'] ?? $now,
            ]);

            $source->increment('balance_minor', $signed);

            return response()->json([
                'message'     => 'Transaction created',
                'transaction' => new TransactionResource($txn->load(['account', 'category'])),
            ], 201);
        });
    }

    private function currencyDecimals(string $code): int
    {
        return $code === 'JPY' ? 0 : 2;
    }

    private function toMinor($major, int $decimals): int
    {
        return (int) round(((float) $major) * (10 ** $decimals));
    }

    private function convertMinor(int $srcMinor, int $srcDecimals, int $dstDecimals, float $rate): int
    {
        $srcMajor = $srcMinor / (10 ** $srcDecimals);
        $dstMajor = $srcMajor * $rate;
        return (int) round($dstMajor * (10 ** $dstDecimals));
    }

    /**
     * Display the specified resource.
     */
    public function show(Transaction $transaction)
    {
        $auth = Auth::user();
        if (!$auth) {
            return response()->json(['error' => 'Unauthenticated.'], 401);
        }

        $user = User::query()->findOrFail($auth->id);

        $transaction->load(['account', 'counterpartyAccount', 'category']);

        $ownsPrimary = optional($transaction->account)->user_id === $user->id;
        $ownsCounterpart = optional($transaction->counterpartyAccount)->user_id === $user->id;

        if ($user->role !== 'admin' && !$ownsPrimary && !$ownsCounterpart) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        return response()->json([
            'transaction' => new TransactionResource($transaction),
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Transaction $transaction)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Transaction $transaction)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Transaction $transaction)
    {
        //
    }
}
