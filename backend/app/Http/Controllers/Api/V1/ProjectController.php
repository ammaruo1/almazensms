<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProjectController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $request->user()->projects()->orderBy('id')->get(['id', 'name', 'status', 'created_at'])]);
    }

    public function store(Request $request, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'daily_message_limit' => ['sometimes', 'integer', 'min:1', 'max:1000'],
        ]);
        $project = $request->user()->projects()->create($data);
        $audit->record($request->user()->id, $project->id, 'project.created', 'project', (string) $project->id);

        return response()->json(['data' => $project->only('id', 'name', 'status', 'created_at')], 201);
    }

    public function show(Request $request, Project $project): JsonResponse
    {
        abort_unless($project->owner_id === $request->user()->id, 404);

        return response()->json(['data' => $project->only('id', 'name', 'status', 'created_at')]);
    }
}
