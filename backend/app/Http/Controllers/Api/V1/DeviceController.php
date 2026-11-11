<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\Project;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class DeviceController extends Controller
{
    public function store(Request $request, Project $project, AuditLogger $audit): JsonResponse
    {
        abort_unless($project->owner_id === $request->user()->id, 404);
        $data = $request->validate(['name' => ['required', 'string', 'max:120']]);
        $plain = 'azm_device_'.Str::random(48);
        $device = $project->devices()->create([
            'name' => $data['name'],
            'token_prefix' => substr($plain, 0, 24),
            'token_hash' => hash('sha256', $plain),
        ]);
        $audit->record($request->user()->id, $project->id, 'device.enrolled', 'device', $device->id, ['name' => $device->name]);

        return response()->json(['data' => ['id' => $device->id, 'name' => $device->name, 'status' => $device->status], 'credential' => $plain], 201)->header('Cache-Control', 'no-store');
    }

    public function index(Request $request, Project $project): JsonResponse
    {
        abort_unless($project->owner_id === $request->user()->id, 404);

        return response()->json(['data' => $project->devices()->orderBy('created_at')->get(['id', 'name', 'status', 'metadata', 'last_heartbeat_at', 'created_at'])]);
    }

    public function revoke(Request $request, Project $project, Device $device, AuditLogger $audit): JsonResponse
    {
        abort_unless($project->owner_id === $request->user()->id && $device->project_id === $project->id, 404);
        $device->forceFill(['revoked_at' => now(), 'status' => 'revoked'])->save();
        $audit->record($request->user()->id, $project->id, 'device.revoked', 'device', $device->id);

        return response()->json(['revoked' => true]);
    }

    public function heartbeat(Request $request): JsonResponse
    {
        $device = $request->attributes->get('gatewayDevice');
        $data = $request->validate([
            'app_version' => ['nullable', 'string', 'max:32'],
            'android_version' => ['nullable', 'string', 'max:32'],
            'api_level' => ['nullable', 'integer', 'min:26', 'max:100'],
            'network_status' => ['nullable', 'in:connected,no_internet,disconnected,unknown'],
            'sim_status' => ['nullable', 'in:ready,not_ready,absent,unknown'],
        ]);
        $device->forceFill(['last_heartbeat_at' => now(), 'metadata' => $data])->save();

        return response()->json(['accepted' => true, 'server_time' => now()->toISOString()]);
    }
}
