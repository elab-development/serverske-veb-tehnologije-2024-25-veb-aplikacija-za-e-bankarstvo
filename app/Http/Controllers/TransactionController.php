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
     * List transactions (admin: all; user: only own/counterparty).
     * Supports filters, search, sorting, and pagination.
     *
     * @OA\Get(
     *   path="/api/transactions",
     *   tags={"Transactions"},
     *   summary="List transactions (with filters, sorting, pagination)",
     *   security={{"bearerAuth":{}}},
     *   @OA\Parameter(name="account_id", in="query", description="Filter by account id", @OA\Schema(type="integer")),
     *   @OA\Parameter(name="category_id", in="query", description="Filter by category id", @OA\Schema(type="integer")),
     *   @OA\Parameter(
     *     name="type[]", in="query", description="Filter by type(s)",
     *     style="form", explode=true,
     *     @OA\Schema(type="array", @OA\Items(type="string", enum={"debit","credit","transfer"}))
     *   ),
     *   @OA\Parameter(
     *     name="currency[]", in="query", description="Filter by currency code(s)",
     *     style="form", explode=true,
     *     @OA\Schema(type="array", @OA\Items(type="string", enum={"RSD","EUR","USD","CHF","JPY"}))
     *   ),
     *   @OA\Parameter(name="date_from", in="query", description="YYYY-MM-DD (inclusive)", @OA\Schema(type="string", format="date")),
     *   @OA\Parameter(name="date_to",   in="query", description="YYYY-MM-DD (inclusive)", @OA\Schema(type="string", format="date")),
     *   @OA\Parameter(name="min_amount", in="query", description="Minimum amount (major units)", @OA\Schema(type="number")),
     *   @OA\Parameter(name="max_amount", in="query", description="Maximum amount (major units)", @OA\Schema(type="number")),
     *   @OA\Parameter(name="q", in="query", description="Search in description", @OA\Schema(type="string")),
     *   @OA\Parameter(name="sort_by", in="query", description="Sort field", @OA\Schema(type="string", enum={"executed_at","amount_minor","id"})),
     *   @OA\Parameter(name="sort_dir", in="query", description="Sort direction", @OA\Schema(type="string", enum={"asc","desc"})),
     *   @OA\Parameter(name="page", in="query", description="Page number", @OA\Schema(type="integer", default=1)),
     *   @OA\Parameter(name="per_page", in="query", description="Items per page (1-100)", @OA\Schema(type="integer", default=15)),
     *   @OA\Response(
     *     response=200,
     *     description="OK",
     *     @OA\JsonContent(
     *       type="object",
     *       @OA\Property(
     *         property="transactions",
     *         type="object",
     *         description="Laravel paginator payload",
     *         @OA\Property(
     *           property="data",
     *           type="array",
     *           @OA\Items(
     *             type="object",
     *             @OA\Property(property="id", type="integer", example=101),
     *             @OA\Property(property="account_id", type="integer", example=12),
     *             @OA\Property(property="type", type="string", example="transfer"),
     *             @OA\Property(property="amount_minor", type="integer", example=-250000),
     *             @OA\Property(property="currency", type="string", example="RSD"),
     *             @OA\Property(property="description", type="string", example="Rent"),
     *             @OA\Property(property="category_id", type="integer", nullable=true, example=3),
     *             @OA\Property(property="counterparty_account_id", type="integer", example=18),
     *             @OA\Property(property="fx_rate", type="number", nullable=true, example=117.18),
     *             @OA\Property(property="fx_base", type="string", nullable=true, example="EUR"),
     *             @OA\Property(property="fx_quote", type="string", nullable=true, example="RSD"),
     *             @OA\Property(property="executed_at", type="string", format="date-time", example="2025-09-04T10:00:00Z")
     *           )
     *         ),
     *         @OA\Property(property="links", type="object"),
     *         @OA\Property(property="meta", type="object")
     *       )
     *     )
     *   ),
     *   @OA\Response(response=401, description="Unauthenticated")
     * )
     */
    public function index(Request $request)
    {
        $auth = Auth::user();
        if (!$auth) {
            return response()->json(['error' => 'Unauthenticated.'], 401);
        }

        $user = User::query()->findOrFail($auth->id);

        $q = Transaction::query()->with(['account', 'category', 'counterpartyAccount']);

        if ($user->role !== 'admin') {
            $q->where(function ($sub) use ($user) {
                $sub->whereHas('account', fn($a) => $a->where('user_id', $user->id))
                    ->orWhereHas('counterpartyAccount', fn($a) => $a->where('user_id', $user->id));
            });
        }

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

        $decimalsForFilter = (is_array($currs) && count($currs) === 1 && reset($currs) === 'JPY') ? 0 : 2;

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
     * Create a transaction.
     * - Users: transfers from their own account.
     * - Admins: transfers + manual credits/debits.
     * - Cross-currency transfers use ExchangeRateService for FX.
     *
     * @OA\Post(
     *   path="/api/transactions",
     *   tags={"Transactions"},
     *   summary="Create a transaction (transfer/credit/debit)",
     *   security={{"bearerAuth":{}}},
     *   @OA\RequestBody(
     *     required=true,
     *     @OA\JsonContent(
     *       required={"type","account_id","amount"},
     *       @OA\Property(property="type", type="string", enum={"debit","credit","transfer"}, example="transfer"),
     *       @OA\Property(property="account_id", type="integer", example=5, description="Source account id"),
     *       @OA\Property(property="counterparty_account_id", type="integer", nullable=true, example=9, description="Required for transfers"),
     *       @OA\Property(property="amount", type="number", example=2500.00, description="Major units"),
     *       @OA\Property(property="description", type="string", maxLength=255, example="Rent"),
     *       @OA\Property(property="category_id", type="integer", nullable=true, example=3),
     *       @OA\Property(property="executed_at", type="string", format="date-time", example="2025-09-04T09:30:00Z")
     *     )
     *   ),
     *   @OA\Response(
     *     response=201,
     *     description="Created",
     *     @OA\JsonContent(
     *       oneOf={
     *         @OA\Schema(
     *           type="object",
     *           required={"message","out","in"},
     *           @OA\Property(property="message", type="string", example="Transfer completed"),
     *           @OA\Property(property="out", ref="#/components/schemas/TransactionItem"),
     *           @OA\Property(property="in",  ref="#/components/schemas/TransactionItem")
     *         ),
     *         @OA\Schema(
     *           type="object",
     *           required={"message","rate","out","in"},
     *           @OA\Property(property="message", type="string", example="FX transfer completed"),
     *           @OA\Property(property="rate", type="number", example=117.1796),
     *           @OA\Property(property="out", ref="#/components/schemas/TransactionItem"),
     *           @OA\Property(property="in",  ref="#/components/schemas/TransactionItem")
     *         ),
     *         @OA\Schema(
     *           type="object",
     *           required={"message","transaction"},
     *           @OA\Property(property="message", type="string", example="Transaction created"),
     *           @OA\Property(property="transaction", ref="#/components/schemas/TransactionItem")
     *         )
     *       }
     *     )
     *   ),
     *   @OA\Response(response=401, description="Unauthenticated"),
     *   @OA\Response(response=403, description="Forbidden"),
     *   @OA\Response(response=409, description="Insufficient funds"),
     *   @OA\Response(response=422, description="Validation error"),
     *   @OA\Response(response=424, description="Failed to fetch FX rate")
     * )
     *
     * @OA\Schema(
     *   schema="TransactionItem",
     *   type="object",
     *   @OA\Property(property="id", type="integer", example=101),
     *   @OA\Property(property="account_id", type="integer", example=5),
     *   @OA\Property(property="type", type="string", example="transfer"),
     *   @OA\Property(property="amount_minor", type="integer", example=-250000),
     *   @OA\Property(property="currency", type="string", example="RSD"),
     *   @OA\Property(property="description", type="string", example="Rent"),
     *   @OA\Property(property="category_id", type="integer", nullable=true, example=3),
     *   @OA\Property(property="counterparty_account_id", type="integer", example=9),
     *   @OA\Property(property="fx_rate", type="number", nullable=true, example=117.1796),
     *   @OA\Property(property="fx_base", type="string", nullable=true, example="EUR"),
     *   @OA\Property(property="fx_quote", type="string", nullable=true, example="RSD"),
     *   @OA\Property(property="executed_at", type="string", format="date-time", example="2025-09-04T09:30:00Z")
     * )
     */
    public function store(Request $request, ExchangeRateService $fx)
    {
        $auth = Auth::user();
        if (!$auth) {
            return response()->json(['error' => 'Unauthenticated.'], 401);
        }
        $user = User::query()->findOrFail($auth->id);

        $rules = [
            'type'        => 'required|in:debit,credit,transfer',
            'account_id'  => 'required|exists:accounts,id',
            'amount'      => 'required|numeric|min:0.01',
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

            if ($validated['type'] === 'transfer') {
                $dest = Account::findOrFail($validated['counterparty_account_id']);

                // Same-currency
                if ($dest->currency === $source->currency) {
                    if ($source->balance_minor < $amountMinor) {
                        return response()->json(['error' => 'Insufficient funds.'], 409);
                    }

                    $tOut = Transaction::create([
                        'account_id'              => $source->id,
                        'type'                    => 'transfer',
                        'amount_minor'            => -$amountMinor,
                        'currency'                => $source->currency,
                        'description'             => $validated['description'] ?? "Transfer to {$dest->name}",
                        'category_id'             => $validated['category_id'] ?? null,
                        'counterparty_account_id' => $dest->id,
                        'executed_at'             => $validated['executed_at'] ?? $now,
                    ]);

                    $tIn = Transaction::create([
                        'account_id'              => $dest->id,
                        'type'                    => 'transfer',
                        'amount_minor'            => +$amountMinor,
                        'currency'                => $dest->currency,
                        'description'             => $validated['description'] ?? "Transfer from {$source->name}",
                        'category_id'             => $validated['category_id'] ?? null,
                        'counterparty_account_id' => $source->id,
                        'executed_at'             => $validated['executed_at'] ?? $now,
                    ]);

                    $source->decrement('balance_minor', $amountMinor);
                    $dest->increment('balance_minor', $amountMinor);

                    return response()->json([
                        'message' => 'Transfer completed',
                        'out'     => new TransactionResource($tOut->load(['account', 'counterpartyAccount', 'category'])),
                        'in'      => new TransactionResource($tIn->load(['account', 'counterpartyAccount', 'category'])),
                    ], 201);
                }

                // Cross-currency transfer (FX)
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
                    'account_id'              => $source->id,
                    'type'                    => 'transfer',
                    'amount_minor'            => -$amountMinor,
                    'currency'                => $source->currency,
                    'description'             => $validated['description'] ?? "FX Transfer to {$dest->name}",
                    'category_id'             => $validated['category_id'] ?? null,
                    'counterparty_account_id' => $dest->id,
                    'fx_rate'                 => $rate,
                    'fx_base'                 => $source->currency,
                    'fx_quote'                => $dest->currency,
                    'executed_at'             => $validated['executed_at'] ?? $now,
                ]);

                $tIn = Transaction::create([
                    'account_id'              => $dest->id,
                    'type'                    => 'transfer',
                    'amount_minor'            => +$dstMinor,
                    'currency'                => $dest->currency,
                    'description'             => $validated['description'] ?? "FX Transfer from {$source->name}",
                    'category_id'             => $validated['category_id'] ?? null,
                    'counterparty_account_id' => $source->id,
                    'fx_rate'                 => $rate,
                    'fx_base'                 => $source->currency,
                    'fx_quote'                => $dest->currency,
                    'executed_at'             => $validated['executed_at'] ?? $now,
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
                'account_id'   => $source->id,
                'type'         => $validated['type'],
                'amount_minor' => $signed,
                'currency'     => $source->currency,
                'description'  => $validated['description'] ?? ucfirst($validated['type']),
                'category_id'  => $validated['category_id'] ?? null,
                'executed_at'  => $validated['executed_at'] ?? now(),
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
     * Get a single transaction (admin or owner of either side).
     *
     * @OA\Get(
     *   path="/api/transactions/{transaction}",
     *   tags={"Transactions"},
     *   summary="Get a single transaction",
     *   security={{"bearerAuth":{}}},
     *   @OA\Parameter(
     *     name="transaction",
     *     in="path",
     *     required=true,
     *     description="Transaction ID",
     *     @OA\Schema(type="integer")
     *   ),
     *   @OA\Response(
     *     response=200,
     *     description="OK",
     *     @OA\JsonContent(
     *       type="object",
     *       @OA\Property(property="transaction", ref="#/components/schemas/TransactionItem")
     *     )
     *   ),
     *   @OA\Response(response=401, description="Unauthenticated"),
     *   @OA\Response(response=403, description="Forbidden")
     * )
     */
    public function show(Transaction $transaction)
    {
        $auth = Auth::user();
        if (!$auth) {
            return response()->json(['error' => 'Unauthenticated.'], 401);
        }

        $user = User::query()->findOrFail($auth->id);

        $transaction->load(['account', 'counterpartyAccount', 'category']);

        $ownsPrimary     = optional($transaction->account)->user_id === $user->id;
        $ownsCounterpart = optional($transaction->counterpartyAccount)->user_id === $user->id;

        if ($user->role !== 'admin' && !$ownsPrimary && !$ownsCounterpart) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        return response()->json([
            'transaction' => new TransactionResource($transaction),
        ]);
    }
}
