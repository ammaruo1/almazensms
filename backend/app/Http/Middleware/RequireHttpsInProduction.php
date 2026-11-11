<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireHttpsInProduction
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('app.env') === 'production' && ! $request->isSecure()) {
            return response()->json(['message' => 'HTTPS is required.'], 400);
        }

        return $next($request);
    }
}
