<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\PasswordGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserController extends Controller
{
    /**
     * GET /users?search= — list, gated by `users.view`.
     */
    public function index(Request $request): JsonResponse
    {
        $query = User::query()->with('roles');

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('email', 'like', "%{$search}%")
                    ->orWhere('full_name', 'like', "%{$search}%");
            });
        }

        $users = $query->orderBy('full_name')->get();

        return response()->json(UserResource::collection($users));
    }

    /**
     * POST /users — gated by `users.create`.
     *
     * When `password` is omitted, a secure temporary password is
     * generated and returned once as `tempPassword` (never shown again).
     * Created users always start with `must_change_password = true`.
     */
    public function store(StoreUserRequest $request): JsonResponse
    {
        $data = $request->validated();

        $providedPassword = $data['password'] ?? null;
        $tempPassword = $providedPassword ? null : PasswordGenerator::generate();
        $plainPassword = $providedPassword ?? $tempPassword;

        $user = User::create([
            'uuid' => (string) Str::uuid(),
            'email' => $data['email'],
            'full_name' => $data['fullName'],
            'password' => Hash::make($plainPassword),
            'must_change_password' => true,
            'status' => 'ACTIVE',
        ]);

        $user->syncRoles($data['roles']);

        AuditLogger::log($request->user(), 'CREATE', 'user', $user->uuid, $user->full_name);

        $response = [
            'user' => new UserResource($user->fresh('roles')),
        ];
        if ($tempPassword) {
            $response['tempPassword'] = $tempPassword;
        }

        return response()->json($response, 201);
    }

    /**
     * PATCH /users/{uuid} — gated by `users.update`.
     */
    public function update(UpdateUserRequest $request, string $uuid): JsonResponse
    {
        $user = User::where('uuid', $uuid)->firstOrFail();
        $data = $request->validated();

        $user->update(array_filter([
            'full_name' => $data['fullName'] ?? null,
            'status' => $data['status'] ?? null,
        ], fn ($v) => $v !== null));

        if (isset($data['roles'])) {
            $user->syncRoles($data['roles']);
        }

        AuditLogger::log($request->user(), 'UPDATE', 'user', $user->uuid, $user->full_name);

        return response()->json(new UserResource($user->fresh('roles')));
    }

    /**
     * POST /users/{uuid}/reset-password — gated by `users.update`.
     * Generates a fresh temporary password, forces a change on next login.
     */
    public function resetPassword(string $uuid): JsonResponse
    {
        $user = User::where('uuid', $uuid)->firstOrFail();
        $tempPassword = PasswordGenerator::generate();

        $user->update([
            'password' => Hash::make($tempPassword),
            'must_change_password' => true,
        ]);

        return response()->json(['tempPassword' => $tempPassword]);
    }
}
