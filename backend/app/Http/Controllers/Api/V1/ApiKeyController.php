<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Models\Project;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ApiKeyController extends Controller
{
    public function store(Request $request, Project $project, AuditLogger $audit): JsonResponse
    {
        abort_unless($project->owner_id === $request->user()->id, 404);
        $data = $request->validate(['name' => ['required', 'string', 'max:80']]);
        $plain = 'azm_live_'.Str::random(48);
        $key = $project->apiKeys()->create([
            'name' => $data['name'],
            ...ApiKey::protect($plain),
        ]);
        $audit->record($request->user()->id, $project->id, 'api_key.created', 'api_key', (string) $key->id, ['name' => $key->name, 'prefix' => $key->prefix]);

        return response()->json(['data' => ['id' => $key->id, 'name' => $key->name, 'prefix' => $key->prefix, 'created_at' => $key->created_at], 'secret' => $plain], 201)->header('Cache-Control', 'no-store');
    }

    public function destroy(Request $request, Project $project, ApiKey $apiKey, AuditLogger $audit): JsonResponse
    {
        abort_unless($project->owner_id === $request->user()->id && $apiKey->project_id === $project->id, 404);
        $apiKey->forceFill(['revoked_at' => now()])->save();
        $audit->record($request->user()->id, $project->id, 'api_key.revoked', 'api_key', (string) $apiKey->id);

        return response()->json(['revoked' => true]);
    }
}
