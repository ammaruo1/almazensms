<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:255'], 'password' => ['required', 'string', 'max:200'], 'device_name' => ['required', 'string', 'max:80']]);
        $user = User::query()->where('email', $data['email'])->first();
        if (! $user || ! Hash::check($data['password'], $user->password)) {
            return response()->json(['message' => 'The provided credentials are incorrect.'], 401);
        }

        if ($user->status !== 'active') {
            return response()->json(['message' => 'Account is disabled.'], 403);
        }
        $token = $user->createToken($data['device_name'], ['projects:manage', 'devices:manage'])->plainTextToken;

        return response()->json(['token_type' => 'Bearer', 'access_token' => $token, 'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'role' => $user->role ?? 'operator']], 201)->header('Cache-Control', 'no-store');
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json(['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'role' => $user->role ?? 'operator']);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['revoked' => true]);
    }
}
