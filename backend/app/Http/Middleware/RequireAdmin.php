<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        abort_unless($user?->role === 'admin' && User::query()->whereKey($user->id)->where('status', 'active')->exists(), 403);

        return $next($request);
    }
}
