<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $plain = $request->header('X-API-Key');
        if (! is_string($plain) || ! str_starts_with($plain, 'azm_live_')) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $key = ApiKey::query()->with('project')->where('prefix', substr($plain, 0, 24))->whereNull('revoked_at')->first();
        if (! $key || $key->project->status !== 'active' || ! hash_equals($key->key_hash, hash('sha256', $plain))) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $key->forceFill(['last_used_at' => now()])->save();
        $request->attributes->set('apiProject', $key->project);
        $request->attributes->set('apiKeyId', $key->id);

        return $next($request);
    }
}
