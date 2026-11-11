<?php

namespace App\Http\Controllers;

use App\Enums\MessageStatus;
use App\Models\ApiKey;
use App\Models\AuditLog;
use App\Models\Device;
use App\Models\Message;
use App\Models\Project;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(): View
    {
        $counts = Message::query()->select('status', DB::raw('count(*) as aggregate'))->groupBy('status')->pluck('aggregate', 'status');

        return view('dashboard.index', [
            'projectCount' => Project::query()->count(),
            'deviceCount' => Device::query()->whereNull('revoked_at')->count(),
            'queuedCount' => $counts[MessageStatus::Queued->value] ?? 0,
            'unknownCount' => $counts[MessageStatus::Unknown->value] ?? 0,
            'latestMessages' => Message::query()->with('project:id,name')->latest()->limit(8)->get(['id', 'project_id', 'status', 'queued_at', 'completed_at']),
            'outboxPending' => DB::table('outbox_events')->whereNull('processed_at')->count(),
        ]);
    }

    public function projects(): View
    {
        return view('dashboard.projects', [
            'projects' => Project::query()->with('owner:id,name,email')->withCount(['devices', 'apiKeys', 'messages'])->orderByDesc('created_at')->paginate(20),
            'owners' => User::query()->where('status', 'active')->orderBy('name')->get(['id', 'name', 'email']),
        ]);
    }

    public function storeProject(Request $request, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'owner_id' => ['required', 'integer', 'exists:users,id,status,active'],
            'daily_message_limit' => ['required', 'integer', 'min:1', 'max:1000'],
        ]);
        $project = Project::query()->create($data);
        $audit->record($request->user()->id, $project->id, 'project.created', 'project', (string) $project->id, ['name' => $project->name]);

        return back()->with('status', 'تم إنشاء المشروع.');
    }

    public function updateProject(Request $request, Project $project, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'owner_id' => ['required', 'integer', 'exists:users,id,status,active'],
            'daily_message_limit' => ['required', 'integer', 'min:1', 'max:1000'],
            'status' => ['required', 'in:active,paused'],
        ]);
        $before = $project->only(['name', 'owner_id', 'daily_message_limit', 'status']);
        $project->update($data);
        $audit->record($request->user()->id, $project->id, 'project.updated', 'project', (string) $project->id, ['before' => $before, 'after' => $data]);

        return back()->with('status', 'تم تحديث المشروع وحدوده.');
    }

    public function users(): View
    {
        return view('dashboard.users', ['users' => User::query()->orderBy('name')->paginate(25)]);
    }

    public function updateUser(Request $request, User $user, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate([
            'role' => ['required', 'in:admin,operator'],
            'status' => ['required', 'in:active,disabled'],
        ]);
        abort_if($user->is($request->user()) && ($data['status'] !== 'active' || $data['role'] !== 'admin'), 422, 'لا يمكن تعطيل حسابك أو خفض صلاحياته.');

        $before = $user->only(['role', 'status']);
        $user->forceFill($data)->save();
        if ($data['status'] === 'disabled') {
            $user->tokens()->delete();
        }
        $audit->record($request->user()->id, null, 'user.updated', 'user', (string) $user->id, ['before' => $before, 'after' => $data]);

        return back()->with('status', 'تم تحديث الحساب.');
    }

    public function devices(): View
    {
        return view('dashboard.devices', [
            'devices' => Device::query()->with('project:id,name')->orderByRaw('last_heartbeat_at is null')->orderByDesc('last_heartbeat_at')->paginate(25),
            'projects' => Project::query()->where('status', 'active')->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function storeDevice(Request $request, Project $project, AuditLogger $audit): Response
    {
        abort_unless($project->status === 'active', 422, 'المشروع متوقف.');
        $data = $request->validate(['name' => ['required', 'string', 'max:120']]);
        $credential = 'azm_device_'.Str::random(48);
        $device = $project->devices()->create([
            'name' => $data['name'],
            'token_prefix' => substr($credential, 0, 24),
            'token_hash' => hash('sha256', $credential),
        ]);
        $audit->record($request->user()->id, $project->id, 'device.enrolled', 'device', $device->id, ['name' => $device->name]);

        return $this->credentialResponse($credential, 'رمز الجهاز', 'احفظ الرمز الآن؛ لا يمكن استعادته بعد مغادرة هذه الصفحة.');
    }

    public function revokeDevice(Request $request, Project $project, Device $device, AuditLogger $audit): RedirectResponse
    {
        abort_unless($device->project_id === $project->id, 404);
        $device->forceFill(['revoked_at' => now(), 'status' => 'revoked'])->save();
        $audit->record($request->user()->id, $project->id, 'device.revoked', 'device', $device->id);

        return back()->with('status', 'تم إلغاء الجهاز.');
    }

    public function keys(): View
    {
        return view('dashboard.keys', [
            'keys' => ApiKey::query()->with('project:id,name')->orderByDesc('created_at')->paginate(25),
            'projects' => Project::query()->where('status', 'active')->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function storeKey(Request $request, Project $project, AuditLogger $audit): Response
    {
        abort_unless($project->status === 'active', 422, 'المشروع متوقف.');
        $data = $request->validate(['name' => ['required', 'string', 'max:80']]);
        $secret = 'azm_live_'.Str::random(48);
        $key = $project->apiKeys()->create([
            'name' => $data['name'],
            'prefix' => substr($secret, 0, 24),
            'key_hash' => hash('sha256', $secret),
        ]);
        $audit->record($request->user()->id, $project->id, 'api_key.created', 'api_key', (string) $key->id, ['name' => $key->name, 'prefix' => $key->prefix]);

        return $this->credentialResponse($secret, 'مفتاح API', 'انسخ المفتاح الآن؛ لا يمكن استعادته بعد مغادرة هذه الصفحة.');
    }

    public function revokeKey(Request $request, Project $project, ApiKey $apiKey, AuditLogger $audit): RedirectResponse
    {
        abort_unless($apiKey->project_id === $project->id, 404);
        $apiKey->forceFill(['revoked_at' => now()])->save();
        $audit->record($request->user()->id, $project->id, 'api_key.revoked', 'api_key', (string) $apiKey->id);

        return back()->with('status', 'تم إلغاء مفتاح API.');
    }

    public function messages(Request $request): View
    {
        $statuses = array_map(static fn (MessageStatus $status): string => $status->value, MessageStatus::cases());
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:64'],
            'status' => ['nullable', 'in:'.implode(',', $statuses)],
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
        ]);
        $messages = Message::query()->with('project:id,name')
            ->when($filters['q'] ?? null, fn ($query, $id) => $query->where('id', 'like', '%'.$id.'%'))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['project_id'] ?? null, fn ($query, $projectId) => $query->where('project_id', $projectId))
            ->latest()->paginate(25)->withQueryString();

        return view('dashboard.messages', ['messages' => $messages, 'projects' => Project::query()->orderBy('name')->get(['id', 'name']), 'statuses' => $statuses]);
    }

    public function usage(): View
    {
        return view('dashboard.usage', [
            'projects' => Project::query()->with(['dailyUsage' => fn ($query) => $query->whereDate('usage_date', today())])->orderBy('name')->get(),
        ]);
    }

    public function audit(): View
    {
        return view('dashboard.audit', ['logs' => AuditLog::query()->with(['user:id,name', 'project:id,name'])->latest('created_at')->paginate(40)]);
    }

    public function diagnostics(): View
    {
        $queue = ['status' => 'غير مستخدم لهذا الاتصال', 'detail' => (string) config('queue.default')];
        if (config('queue.default') === 'redis') {
            try {
                Redis::connection()->ping();
                $queue = ['status' => 'متصل', 'detail' => 'اتصال Redis متاح'];
            } catch (\Throwable) {
                $queue = ['status' => 'غير متاح', 'detail' => 'تعذر التحقق من اتصال Redis'];
            }
        }

        try {
            DB::connection()->getPdo();
            $database = 'متصل';
            $failedJobs = DB::table('failed_jobs')->count();
            $pendingOutbox = DB::table('outbox_events')->whereNull('processed_at')->count();
        } catch (\Throwable) {
            $failedJobs = null;
            $pendingOutbox = null;
            $database = 'تعذر الاتصال';
        }

        return view('dashboard.diagnostics', [
            'queue' => $queue,
            'failedJobs' => $failedJobs,
            'pendingOutbox' => $pendingOutbox,
            'staleDevices' => Device::query()->whereNull('revoked_at')->where(fn ($query) => $query->whereNull('last_heartbeat_at')->orWhere('last_heartbeat_at', '<', now()->subMinutes(5)))->count(),
            'database' => $database,
        ]);
    }

    private function credentialResponse(string $credential, string $title, string $notice): Response
    {
        return response()->view('dashboard.credential', compact('credential', 'title', 'notice'), 201)
            ->header('Cache-Control', 'no-store, private')
            ->header('Pragma', 'no-cache')
            ->header('Referrer-Policy', 'no-referrer')
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }
}
