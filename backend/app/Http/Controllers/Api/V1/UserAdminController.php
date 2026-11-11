<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserAdminController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => User::query()->orderBy('id')->get(['id', 'name', 'email', 'role', 'status', 'created_at'])]);
    }

    public function update(Request $request, User $user, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'role' => ['sometimes', 'required', 'in:admin,operator'],
            'status' => ['sometimes', 'required', 'in:active,disabled'],
        ]);
        if ($data === []) {
            return response()->json(['message' => 'At least one of role or status is required.'], 422);
        }
        abort_if($user->is($request->user()) && (($data['status'] ?? $user->status) !== 'active' || ($data['role'] ?? $user->role) !== 'admin'), 422, 'You cannot disable or demote your own account.');

        $user->forceFill($data)->save();
        if (($data['status'] ?? null) === 'disabled') {
            $user->tokens()->delete();
        }
        $audit->record($request->user()->id, null, 'user.updated', 'user', (string) $user->id, $data);

        return response()->json(['data' => $user->only('id', 'name', 'email', 'role', 'status')]);
    }
}
