<?php

namespace App\Http\Middleware;

use App\Models\Device;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateDevice
{
    public function handle(Request $request, Closure $next): Response
    {
        $plain = $request->bearerToken();
        if (! is_string($plain) || ! str_starts_with($plain, 'azm_device_')) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $device = Device::query()->with('project')->where('token_prefix', substr($plain, 0, 24))->whereNull('revoked_at')->first();
        if (! $device || $device->status !== 'active' || $device->project->status !== 'active' || ! hash_equals($device->token_hash, hash('sha256', $plain))) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $request->attributes->set('gatewayDevice', $device);

        return $next($request);
    }
}
