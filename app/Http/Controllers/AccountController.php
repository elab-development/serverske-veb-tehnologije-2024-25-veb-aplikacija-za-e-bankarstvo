<?php

namespace App\Http\Controllers;

use App\Http\Resources\AccountResource;
use App\Models\Account;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class AccountController extends Controller
{
    /**
     * List accounts (auth required).
     * - Admin: all accounts
     * - User: only own accounts
     *
     * @OA\Get(
     *   path="/api/accounts",
     *   tags={"Accounts"},
     *   summary="List accounts (admin: all, user: own)",
     *   security={{"bearerAuth":{}}},
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
     *           @OA\Property(property="user_id", type="integer", example=3),
     *           @OA\Property(property="number", type="string", example="RS12 3456 7890 1234 5678"),
     *           @OA\Property(property="currency", type="string", example="RSD", enum={"RSD","EUR","USD","CHF","JPY"}),
     *           @OA\Property(property="balance_minor", type="integer", example=1250000),
     *           @OA\Property(property="balance", type="number", format="float", example=12500.00),
     *           @OA\Property(property="name", type="string", example="Main RSD"),
     *           @OA\Property(property="created_at", type="string", format="date-time", example="2025-09-04T10:00:00Z"),
     *           @OA\Property(property="updated_at", type="string", format="date-time", example="2025-09-04T10:00:00Z")
     *         )
     *       )
     *     )
     *   ),
     *   @OA\Response(response=401, description="Unauthenticated"),
     *   @OA\Response(response=404, description="No accounts found.")
     * )
     */
    public function index()
    {
        $authUser = Auth::user();
        if (!$authUser) {
            return response()->json(['error' => 'Unauthenticated.'], 401);
        }

        $user = User::query()->findOrFail($authUser->id);

        $accounts = $user->role === 'admin'
            ? Account::with('user')->orderByDesc('id')->get()
            : $user->accounts()->with('user')->orderByDesc('id')->get();

        if ($accounts->isEmpty()) {
            return response()->json(['message' => 'No accounts found.'], 404);
        }

        return response()->json([
            'accounts' => AccountResource::collection($accounts),
        ]);
    }

    /**
     * Create a new account (auth required).
     * - Admin: must pass user_id (for any user).
     * - User: opens account for self (user_id ignored).
     *
     * @OA\Post(
     *   path="/api/accounts",
     *   tags={"Accounts"},
     *   summary="Create a new account",
     *   security={{"bearerAuth":{}}},
     *   @OA\RequestBody(
     *     required=true,
     *     @OA\JsonContent(
     *       required={"currency"},
     *       @OA\Property(property="currency", type="string", enum={"RSD","EUR","USD","CHF","JPY"}, example="EUR"),
     *       @OA\Property(property="name", type="string", maxLength=255, example="EUR Savings"),
     *       @OA\Property(property="user_id", type="integer", example=5, description="Required if caller is admin; ignored for regular users")
     *     )
     *   ),
     *   @OA\Response(
     *     response=201,
     *     description="Account created",
     *     @OA\JsonContent(
     *       type="object",
     *       @OA\Property(property="message", type="string", example="Account created successfully"),
     *       @OA\Property(property="account",
     *         type="object",
     *         @OA\Property(property="id", type="integer", example=21),
     *         @OA\Property(property="user_id", type="integer", example=5),
     *         @OA\Property(property="number", type="string", example="EU48 6986 5935 1665 6363"),
     *         @OA\Property(property="currency", type="string", example="EUR"),
     *         @OA\Property(property="balance_minor", type="integer", example=0),
     *         @OA\Property(property="balance", type="number", example=0),
     *         @OA\Property(property="name", type="string", example="EUR Savings")
     *       )
     *     )
     *   ),
     *   @OA\Response(response=401, description="Unauthenticated"),
     *   @OA\Response(response=422, description="Validation error")
     * )
     */
    public function store(Request $request)
    {
        $authUser = Auth::user();
        if (!$authUser) {
            return response()->json(['error' => 'Unauthenticated.'], 401);
        }

        $user = User::query()->findOrFail($authUser->id);

        $rules = [
            'currency' => 'required|in:RSD,EUR,USD,CHF,JPY',
            'name' => 'nullable|string|max:255',
        ];
        if ($user->role === 'admin') {
            $rules['user_id'] = 'required|exists:users,id';
        }

        $validated = $request->validate($rules);

        $ownerId = $user->role === 'admin'
            ? (int) $validated['user_id']
            : $user->id;

        $number = $this->generateUniqueNumber($validated['currency']);

        $account = Account::create([
            'user_id' => $ownerId,
            'number' => $number,
            'currency' => $validated['currency'],
            'balance_minor' => 0,
            'name' => $validated['name'] ?? ($validated['currency'] . ' Account'),
        ]);

        return response()->json([
            'message' => 'Account created successfully',
            'account' => new AccountResource($account->load('user')),
        ], 201);
    }

    /**
     * Get a single account (auth required).
     * - Allowed if admin or owner of the account.
     *
     * @OA\Get(
     *   path="/api/accounts/{account}",
     *   tags={"Accounts"},
     *   summary="Get a single account (admin or owner)",
     *   security={{"bearerAuth":{}}},
     *   @OA\Parameter(
     *     name="account",
     *     in="path",
     *     required=true,
     *     description="Account ID",
     *     @OA\Schema(type="integer")
     *   ),
     *   @OA\Response(
     *     response=200,
     *     description="OK",
     *     @OA\JsonContent(
     *       type="object",
     *       @OA\Property(property="account",
     *         type="object",
     *         @OA\Property(property="id", type="integer", example=12),
     *         @OA\Property(property="user_id", type="integer", example=3),
     *         @OA\Property(property="number", type="string", example="RS12 3456 7890 1234 5678"),
     *         @OA\Property(property="currency", type="string", example="RSD"),
     *         @OA\Property(property="balance_minor", type="integer", example=1250000),
     *         @OA\Property(property="balance", type="number", example=12500.00),
     *         @OA\Property(property="name", type="string", example="Main RSD")
     *       )
     *     )
     *   ),
     *   @OA\Response(response=401, description="Unauthenticated"),
     *   @OA\Response(response=403, description="Forbidden")
     * )
     */
    public function show(Account $account)
    {
        $authUser = Auth::user();
        if (!$authUser) {
            return response()->json(['error' => 'Unauthenticated.'], 401);
        }

        $user = User::query()->findOrFail($authUser->id);

        if ($user->role !== 'admin' && $account->user_id !== $user->id) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $account->load('user');

        return response()->json([
            'account' => new AccountResource($account),
        ]);
    }

    /**
     * Update an account (auth required).
     * - Admin or owner can rename the account (name only).
     *
     * @OA\Put(
     *   path="/api/accounts/{account}",
     *   tags={"Accounts"},
     *   summary="Update an account name (admin or owner)",
     *   security={{"bearerAuth":{}}},
     *   @OA\Parameter(
     *     name="account",
     *     in="path",
     *     required=true,
     *     description="Account ID",
     *     @OA\Schema(type="integer")
     *   ),
     *   @OA\RequestBody(
     *     required=false,
     *     @OA\JsonContent(
     *       @OA\Property(property="name", type="string", maxLength=255, example="Everyday RSD")
     *     )
     *   ),
     *   @OA\Response(
     *     response=200,
     *     description="Account updated",
     *     @OA\JsonContent(
     *       type="object",
     *       @OA\Property(property="message", type="string", example="Account updated successfully"),
     *       @OA\Property(property="account",
     *         type="object",
     *         @OA\Property(property="id", type="integer", example=12),
     *         @OA\Property(property="name", type="string", example="Everyday RSD")
     *       )
     *     )
     *   ),
     *   @OA\Response(response=401, description="Unauthenticated"),
     *   @OA\Response(response=403, description="Forbidden"),
     *   @OA\Response(response=422, description="Validation error")
     * )
     */
    public function update(Request $request, Account $account)
    {
        $authUser = Auth::user();
        if (!$authUser) {
            return response()->json(['error' => 'Unauthenticated.'], 401);
        }

        $user = User::query()->findOrFail($authUser->id);

        if ($user->role !== 'admin' && $account->user_id !== $user->id) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'name' => 'sometimes|nullable|string|max:255',
        ]);

        if (array_key_exists('name', $validated)) {
            $account->name = $validated['name'];
            $account->save();
        }

        return response()->json([
            'message' => 'Account updated successfully',
            'account' => new AccountResource($account->fresh()->load('user')),
        ]);
    }

    /**
     * Delete (close) an account (auth required).
     * - Admin or owner
     * - Only allowed if balance is zero
     *
     * @OA\Delete(
     *   path="/api/accounts/{account}",
     *   tags={"Accounts"},
     *   summary="Close an account (admin or owner, balance must be 0)",
     *   security={{"bearerAuth":{}}},
     *   @OA\Parameter(
     *     name="account",
     *     in="path",
     *     required=true,
     *     description="Account ID",
     *     @OA\Schema(type="integer")
     *   ),
     *   @OA\Response(
     *     response=200,
     *     description="Account closed",
     *     @OA\JsonContent(
     *       type="object",
     *       @OA\Property(property="message", type="string", example="Account closed successfully")
     *     )
     *   ),
     *   @OA\Response(response=401, description="Unauthenticated"),
     *   @OA\Response(response=403, description="Forbidden"),
     *   @OA\Response(
     *     response=422,
     *     description="Balance not zero",
     *     @OA\JsonContent(
     *       type="object",
     *       @OA\Property(property="error", type="string", example="Account cannot be closed while balance is not zero.")
     *     )
     *   )
     * )
     */
    public function destroy(Account $account)
    {
        $authUser = Auth::user();
        if (!$authUser) {
            return response()->json(['error' => 'Unauthenticated.'], 401);
        }

        $user = User::query()->findOrFail($authUser->id);

        if ($user->role !== 'admin' && $account->user_id !== $user->id) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        if ((int) $account->balance_minor !== 0) {
            return response()->json([
                'error' => 'Account cannot be closed while balance is not zero.',
            ], 422);
        }

        $account->delete();

        return response()->json(['message' => 'Account closed successfully']);
    }

    protected function generateUniqueNumber(string $currency): string
    {
        $prefix = match ($currency) {
            'RSD' => 'RS',
            'EUR' => 'EU',
            'USD' => 'US',
            'CHF' => 'CH',
            'JPY' => 'JP',
        };

        for ($i = 0; $i < 5; $i++) {
            $candidate = $prefix . Str::upper(' ' . fake()->numerify('## #### #### #### ####'));
            if (!Account::where('number', $candidate)->exists()) {
                return $candidate;
            }
        }

        return $prefix . ' ' . now()->timestamp . ' ' . fake()->numerify('####');
    }
}
