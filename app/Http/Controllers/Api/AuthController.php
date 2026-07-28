<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ChangePasswordRequest;
use App\Http\Requests\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    /**
     * POST /auth/login — {email, password} → 200 {token, user} / 401 {message}.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $email = trim((string) $request->input('email'));
        $password = (string) $request->input('password');

        $user = User::where('email', $email)->first();

        if (! $user || ! Hash::check($password, $user->password)) {
            return response()->json([
                'message' => 'Invalid credentials.',
            ], 401);
        }

        if ($user->status !== 'ACTIVE') {
            return response()->json([
                'message' => 'Invalid credentials.',
            ], 401);
        }

        $token = $user->createToken('devon-dashboard')->plainTextToken;

        AuditLogger::log($user, 'LOGIN', 'user', $user->uuid, $user->email);

        return response()->json([
            'token' => $token,
            'user' => new UserResource($user),
        ]);
    }

    /**
     * GET /auth/me — current authenticated user.
     *
     * Uses response()->json() rather than returning the Resource directly —
     * the latter triggers Laravel's automatic `{"data": ...}` envelope,
     * which would break the flat `SessionUser` contract.
     */
    public function me(Request $request): JsonResponse
    {
        return response()->json(new UserResource($request->user()));
    }

    /**
     * POST /auth/logout — revoke the current token → 204.
     */
    public function logout(Request $request): Response
    {
        $user = $request->user();
        AuditLogger::log($user, 'LOGOUT', 'user', $user->uuid, $user->email);

        $user->currentAccessToken()->delete();

        return response()->noContent();
    }

    /**
     * POST /auth/change-password — {current_password, new_password} → 204 / 422.
     */
    public function changePassword(ChangePasswordRequest $request): Response
    {
        $user = $request->user();
        $user->update([
            'password' => Hash::make((string) $request->input('new_password')),
            'must_change_password' => false,
            'password_changed_at' => now(),
        ]);

        AuditLogger::log($user, 'PASSWORD_CHANGED', 'user', $user->uuid, $user->email);

        return response()->noContent();
    }
}
