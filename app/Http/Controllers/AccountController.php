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
     * Display a listing of the resource.
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
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
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
            'account' => new \App\Http\Resources\AccountResource($account->load('user')),
        ], 201);
    }

    /**
     * Display the specified resource.
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
     * Show the form for editing the specified resource.
     */
    public function edit(Account $account)
    {
        //
    }

    /**
     * Update the specified resource in storage.
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
     * Remove the specified resource from storage.
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
