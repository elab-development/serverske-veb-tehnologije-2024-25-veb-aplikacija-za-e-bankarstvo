<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class AuthController extends Controller
{
    /**
     * Register a new user.
     *
     * @OA\Post(
     *   path="/api/register",
     *   tags={"Auth"},
     *   summary="Register a new user",
     *   @OA\RequestBody(
     *     required=true,
     *     @OA\JsonContent(
     *       required={"name","email","password"},
     *       @OA\Property(property="name", type="string", maxLength=255, example="Ana Kalinić"),
     *       @OA\Property(property="email", type="string", format="email", example="ana@gmail.com"),
     *       @OA\Property(property="password", type="string", format="password", minLength=8, example="password")
     *     )
     *   ),
     *   @OA\Response(
     *     response=200,
     *     description="User registered",
     *     @OA\JsonContent(
     *       type="object",
     *       @OA\Property(property="data", type="object",
     *         @OA\Property(property="id", type="integer", example=1),
     *         @OA\Property(property="name", type="string", example="Ana Kalinić"),
     *         @OA\Property(property="email", type="string", example="ana@gmail.com"),
     *       ),
     *       @OA\Property(property="access_token", type="string", example="1|uB7...token..."),
     *       @OA\Property(property="token_type", type="string", example="Bearer")
     *     )
     *   ),
     *   @OA\Response(
     *     response=422,
     *     description="Validation errors",
     *     @OA\JsonContent(
     *       type="object",
     *       @OA\Property(
     *         property="errors",
     *         type="object",
     *         example={"email":{"The email has already been taken."},"password":{"The password field must be at least 8 characters."}}
     *       )
     *     )
     *   )
     * )
     */
    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|string|max:255|email|unique:users',
            'password' => 'required|string|min:8',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors());
        }

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password)
        ]);

        $token = $user->createToken('auth_token')->plainTextToken;
        return response()->json([
            'data' => $user,
            'access_token' => $token,
            'token_type' => 'Bearer'
        ]);
    }

    /**
     * Login and get an access token.
     *
     * @OA\Post(
     *   path="/api/login",
     *   tags={"Auth"},
     *   summary="Login and obtain a bearer token",
     *   @OA\RequestBody(
     *     required=true,
     *     @OA\JsonContent(
     *       required={"email","password"},
     *       @OA\Property(property="email", type="string", format="email", example="ana@gmail.com"),
     *       @OA\Property(property="password", type="string", format="password", example="password")
     *     )
     *   ),
     *   @OA\Response(
     *     response=200,
     *     description="Logged in",
     *     @OA\JsonContent(
     *       type="object",
     *       @OA\Property(property="message", type="string", example="Ana Kalinić logged in"),
     *       @OA\Property(property="access_token", type="string", example="1|uB7...token..."),
     *       @OA\Property(property="token_type", type="string", example="Bearer")
     *     )
     *   ),
     *   @OA\Response(
     *     response=401,
     *     description="Invalid credentials",
     *     @OA\JsonContent(
     *       type="object",
     *       @OA\Property(property="message", type="string", example="WRONG INPUT")
     *     )
     *   )
     * )
     */
    public function login(Request $request)
    {
        if (!Auth::attempt($request->only('email', 'password'))) {
            return response()->json(['message' => 'WRONG INPUT'], 401);
        }

        $user = User::where('email', $request['email'])->firstOrFail();

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => $user->name . ' logged in',
            'access_token' => $token,
            'token_type' => 'Bearer'
        ]);
    }

    /**
     * Logout (revoke all tokens of the current user).
     *
     * @OA\Post(
     *   path="/api/logout",
     *   tags={"Auth"},
     *   summary="Logout current user (revoke all tokens)",
     *   security={{"bearerAuth":{}}},
     *   @OA\Response(
     *     response=200,
     *     description="Logged out",
     *     @OA\JsonContent(
     *       type="object",
     *       @OA\Property(property="message", type="string", example="You have successfully logged out.")
     *     )
     *   ),
     *   @OA\Response(
     *     response=401,
     *     description="Unauthenticated"
     *   )
     * )
     */
    public function logout()
    {
        auth()->user()->tokens()->delete();

        return [
            'message' => 'You have successfully logged out.'
        ];
    }
}
