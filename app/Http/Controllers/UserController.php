<?php

namespace App\Http\Controllers;

use App\Http\Resources\AccountResource;
use App\Http\Resources\TransactionResource;
use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class UserController extends Controller
{
    /**
     * Get all accounts for a specific user (admin only).
     *
     * @OA\Get(
     *   path="/api/users/{user}/accounts",
     *   tags={"Users"},
     *   summary="List accounts for a given user (admin only)",
     *   security={{"bearerAuth":{}}},
     *   @OA\Parameter(
     *     name="user",
     *     in="path",
     *     required=true,
     *     description="User ID",
     *     @OA\Schema(type="integer")
     *   ),
     *   @OA\Response(
     *     response=200,
     *     description="OK",
     *     @OA\JsonContent(
     *       type="object",
     *       @OA\Property(
     *         property="accounts",
     *         type="array",
     *         @OA\Items(
     *           type="object",
     *           @OA\Property(property="id", type="integer", example=12),
     *           @OA\Property(property="user_id", type="integer", example=7),
     *           @OA\Property(property="number", type="string", example="RS12 3456 7890 1234 5678"),
     *           @OA\Property(property="currency", type="string", enum={"RSD","EUR","USD","CHF","JPY"}, example="RSD"),
     *           @OA\Property(property="balance_minor", type="integer", example=1250000),
     *           @OA\Property(property="balance", type="number", example=12500.00),
     *           @OA\Property(property="name", type="string", example="Main RSD"),
     *           @OA\Property(property="created_at", type="string", format="date-time", example="2025-09-04T10:00:00Z"),
     *           @OA\Property(property="updated_at", type="string", format="date-time", example="2025-09-04T10:00:00Z")
     *         )
     *       )
     *     )
     *   ),
     *   @OA\Response(response=401, description="Unauthenticated"),
     *   @OA\Response(response=403, description="Only admins can access this endpoint"),
     *   @OA\Response(response=404, description="No accounts found for this user.")
     * )
     */
    public function accounts(User $user)
    {
        $authUser = Auth::user();
        if (!$authUser) {
            return response()->json(['error' => 'Unauthenticated.'], 401);
        }
        $caller = User::query()->findOrFail($authUser->id);
        if ($caller->role !== 'admin') {
            return response()->json(['error' => 'Only admins can access this endpoint'], 403);
        }

        $accounts = Account::with('user')
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->get();

        if ($accounts->isEmpty()) {
            return response()->json(['message' => 'No accounts found for this user.'], 404);
        }

        return response()->json([
            'accounts' => AccountResource::collection($accounts),
        ]);
    }

    /**
     * Get all transactions involving a specific user (admin only).
     * Includes transactions where the user's accounts are either primary or counterparty.
     *
     * @OA\Get(
     *   path="/api/users/{user}/transactions",
     *   tags={"Users"},
     *   summary="List transactions for a given user (admin only)",
     *   security={{"bearerAuth":{}}},
     *   @OA\Parameter(
     *     name="user",
     *     in="path",
     *     required=true,
     *     description="User ID",
     *     @OA\Schema(type="integer")
     *   ),
     *   @OA\Response(
     *     response=200,
     *     description="OK",
     *     @OA\JsonContent(
     *       type="object",
     *       @OA\Property(
     *         property="transactions",
     *         type="array",
     *         @OA\Items(
     *           type="object",
     *           @OA\Property(property="id", type="integer", example=101),
     *           @OA\Property(property="account_id", type="integer", example=12),
     *           @OA\Property(property="type", type="string", enum={"debit","credit","transfer"}, example="transfer"),
     *           @OA\Property(property="amount_minor", type="integer", example=-250000),
     *           @OA\Property(property="currency", type="string", enum={"RSD","EUR","USD","CHF","JPY"}, example="RSD"),
     *           @OA\Property(property="description", type="string", example="Rent"),
     *           @OA\Property(property="category_id", type="integer", nullable=true, example=3),
     *           @OA\Property(property="counterparty_account_id", type="integer", example=18),
     *           @OA\Property(property="fx_rate", type="number", nullable=true, example=117.1796),
     *           @OA\Property(property="fx_base", type="string", nullable=true, example="EUR"),
     *           @OA\Property(property="fx_quote", type="string", nullable=true, example="RSD"),
     *           @OA\Property(property="executed_at", type="string", format="date-time", example="2025-09-04T10:00:00Z")
     *         )
     *       )
     *     )
     *   ),
     *   @OA\Response(response=401, description="Unauthenticated"),
     *   @OA\Response(response=403, description="Only admins can access this endpoint"),
     *   @OA\Response(response=404, description="No transactions found for this user.")
     * )
     */
    public function transactions(User $user)
    {
        $authUser = Auth::user();
        if (!$authUser) {
            return response()->json(['error' => 'Unauthenticated.'], 401);
        }
        $caller = User::query()->findOrFail($authUser->id);
        if ($caller->role !== 'admin') {
            return response()->json(['error' => 'Only admins can access this endpoint'], 403);
        }

        $transactions = Transaction::with(['account', 'counterpartyAccount', 'category'])
            ->where(function ($q) use ($user) {
                $q->whereHas('account', fn($a) => $a->where('user_id', $user->id))
                    ->orWhereHas('counterpartyAccount', fn($a) => $a->where('user_id', $user->id));
            })
            ->orderByDesc('executed_at')
            ->get();

        if ($transactions->isEmpty()) {
            return response()->json(['message' => 'No transactions found for this user.'], 404);
        }

        return response()->json([
            'transactions' => TransactionResource::collection($transactions),
        ]);
    }
}
