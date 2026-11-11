<?php

namespace App\Http\Controllers;

use App\Enums\MessageStatus;
use App\Models\ApiKey;
use App\Models\AuditLog;
use App\Models\Device;
use App\Models\Message;
use App\Models\OtpChallenge;
use App\Models\Project;
use App\Models\User;
use App\Services\AuditLogger;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Throwable;

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
        $today = now('UTC')->toDateString();

        return view('dashboard.keys', [
            'keys' => ApiKey::query()
                ->with([
                    'project:id,name,daily_message_limit',
                    'project.dailyUsage' => fn ($query) => $query->whereDate('usage_date', $today),
                    'dailyUsage' => fn ($query) => $query->whereDate('usage_date', $today),
                ])
                ->orderByDesc('created_at')
                ->paginate(25),
            'projects' => Project::query()->where('status', 'active')->orderBy('name')->get(['id', 'name', 'daily_message_limit']),
            'today' => $today,
        ]);
    }

    public function storeKey(Request $request, Project $project, AuditLogger $audit): Response
    {
        abort_unless($project->status === 'active', 422, 'المشروع متوقف.');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'daily_message_limit' => ['required', 'integer', 'min:1', 'max:1000'],
            'requests_per_minute' => ['required', 'integer', 'min:1', 'max:120'],
        ]);
        $secret = 'azm_live_'.Str::random(48);
        $key = $project->apiKeys()->create([
            'name' => $data['name'],
            'prefix' => substr($secret, 0, 24),
            'key_hash' => hash('sha256', $secret),
            'daily_message_limit' => $data['daily_message_limit'],
            'requests_per_minute' => $data['requests_per_minute'],
        ]);
        $audit->record($request->user()->id, $project->id, 'api_key.created', 'api_key', (string) $key->id, [
            'name' => $key->name,
            'prefix' => $key->prefix,
            'daily_message_limit' => $key->daily_message_limit,
            'requests_per_minute' => $key->requests_per_minute,
        ]);

        return $this->credentialResponse($secret, 'مفتاح API', 'انسخ المفتاح الآن؛ لا يمكن استعادته بعد مغادرة هذه الصفحة.');
    }

    public function updateKeyQuota(Request $request, Project $project, ApiKey $apiKey, AuditLogger $audit): RedirectResponse
    {
        abort_unless($apiKey->project_id === $project->id, 404);
        $data = $request->validate([
            'daily_message_limit' => ['required', 'integer', 'min:1', 'max:1000'],
            'requests_per_minute' => ['required', 'integer', 'min:1', 'max:120'],
        ]);
        $before = [
            'daily_message_limit' => (int) $apiKey->daily_message_limit,
            'requests_per_minute' => (int) $apiKey->requests_per_minute,
        ];
        $apiKey->update($data);
        $audit->record($request->user()->id, $project->id, 'api_key.quota_updated', 'api_key', (string) $apiKey->id, [
            'api_key_id' => $apiKey->id,
            'before' => $before,
            'after' => [
                'daily_message_limit' => (int) $data['daily_message_limit'],
                'requests_per_minute' => (int) $data['requests_per_minute'],
            ],
        ]);

        return back()->with('status', 'تم تحديث حصة مفتاح API.');
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
        $today = now('UTC')->toDateString();

        return view('dashboard.usage', [
            'today' => $today,
            'projects' => Project::query()->with(['dailyUsage' => fn ($query) => $query->whereDate('usage_date', $today)])->orderBy('name')->get(),
            'keys' => ApiKey::query()
                ->with([
                    'project:id,name,daily_message_limit',
                    'project.dailyUsage' => fn ($query) => $query->whereDate('usage_date', $today),
                    'dailyUsage' => fn ($query) => $query->whereDate('usage_date', $today),
                ])
                ->orderBy('project_id')
                ->orderBy('id')
                ->get(),
        ]);
    }

    public function otp(): View
    {
        return view('dashboard.otp', [
            'projects' => Project::query()->orderBy('name')->get(),
            'challenges' => OtpChallenge::query()->with('project:id,name')->latest()->paginate(40),
        ]);
    }

    public function updateOtp(Request $request, Project $project, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate([
            'otp_enabled' => ['required', 'boolean'],
            'otp_ttl_seconds' => ['required', 'integer', 'min:60', 'max:1800'],
            'otp_max_attempts' => ['required', 'integer', 'min:1', 'max:10'],
            'otp_resend_cooldown_seconds' => ['required', 'integer', 'min:15', 'max:3600'],
        ]);
        $before = $project->only(['otp_enabled', 'otp_ttl_seconds', 'otp_max_attempts', 'otp_resend_cooldown_seconds']);
        $after = [
            'otp_enabled' => $request->boolean('otp_enabled'),
            'otp_ttl_seconds' => (int) $data['otp_ttl_seconds'],
            'otp_max_attempts' => (int) $data['otp_max_attempts'],
            'otp_resend_cooldown_seconds' => (int) $data['otp_resend_cooldown_seconds'],
        ];
        $project->update($after);
        $audit->record($request->user()->id, $project->id, 'otp.settings_updated', 'project', (string) $project->id, [
            'before' => $before,
            'after' => $after,
        ]);

        return back()->with('status', $after['otp_enabled'] ? 'تم حفظ إعدادات OTP وتفعيلها لهذا المشروع.' : 'تم حفظ إعدادات OTP. الخدمة متوقفة لهذا المشروع.');
    }

    public function audit(): View
    {
        return view('dashboard.audit', ['logs' => AuditLog::query()->with(['user:id,name', 'project:id,name'])->latest('created_at')->paginate(40)]);
    }

    public function diagnostics(): View
    {
        $connection = (string) config('queue.default');
        $driver = (string) config("queue.connections.{$connection}.driver", $connection);
        $queueLabel = match ($driver) {
            'database' => 'Database Queue',
            'redis' => 'Redis',
            default => $driver,
        };
        $queueDetail = match ($driver) {
            'database' => 'QUEUE_CONNECTION=database. الطابور جدول jobs في MariaDB أو MySQL عبر Laravel Database Queue.',
            'redis' => $this->redisQueueDetail(),
            default => 'اتصال الطابور الفعلي هو '.$driver.'.',
        };

        try {
            DB::connection()->getPdo();
            $database = 'متصل';
        } catch (Throwable) {
            $database = 'تعذر الاتصال';
        }

        $version = '';
        $engine = 'غير معروف';
        if ($database === 'متصل') {
            try {
                $version = (string) DB::scalar('select version()');
            } catch (Throwable) {
                try {
                    $version = (string) DB::scalar('select sqlite_version()');
                } catch (Throwable) {
                    $version = '';
                }
            }
            $engine = str_contains($version, 'MariaDB')
                ? 'MariaDB'
                : (str_contains(strtolower($version), 'mysql') ? 'MySQL' : DB::connection()->getDriverName());
        }

        $pendingJobs = null;
        $oldestJob = null;
        if ($driver === 'database') {
            try {
                $table = (string) config("queue.connections.{$connection}.table", 'jobs');
                $pendingJobs = DB::table($table)->count();
                $oldestCreated = DB::table($table)->min('created_at');
                $oldestJob = is_numeric($oldestCreated) ? Carbon::createFromTimestampUTC((int) $oldestCreated)->toIso8601String() : null;
            } catch (Throwable) {
                $pendingJobs = null;
                $oldestJob = null;
            }
        }

        try {
            $pendingOutbox = DB::table('outbox_events')->whereNull('processed_at')->count();
            $oldestOutboxRaw = DB::table('outbox_events')->whereNull('processed_at')->orderBy('created_at')->value('created_at');
            $oldestOutbox = $oldestOutboxRaw ? Carbon::parse($oldestOutboxRaw)->toIso8601String() : null;
            $failedJobs = DB::table('failed_jobs')->count();
            $oldestFailedRaw = DB::table('failed_jobs')->orderBy('failed_at')->value('failed_at');
            $oldestFailed = $oldestFailedRaw ? Carbon::parse($oldestFailedRaw)->toIso8601String() : null;
        } catch (Throwable) {
            $pendingOutbox = null;
            $oldestOutbox = null;
            $failedJobs = null;
            $oldestFailed = null;
        }

        try {
            $heartbeat = DB::table('scheduler_heartbeats')->where('name', 'schedule')->value('last_ran_at');
            $schedulerStatus = $heartbeat ? 'آخر تشغيل مسجّل' : 'غير مؤكد';
            $schedulerDetail = $heartbeat
                ? Carbon::parse($heartbeat)->toIso8601String().' — كتبه أمر scheduler:heartbeat عند تنفيذ الجدولة. هذا ليس إثباتًا أن Cron على Hostinger مضبوط.'
                : 'غير مؤكد. تعريف الجدولة في Laravel لا يعني أن Cron يعمل.';
        } catch (Throwable) {
            $schedulerStatus = 'غير مؤكد';
            $schedulerDetail = 'غير مؤكد. تعذر قراءة سجل نبض الجدولة.';
        }

        return view('dashboard.diagnostics', [
            'database' => $database,
            'engine' => $engine,
            'databaseVersion' => $version,
            'queueLabel' => $queueLabel,
            'queueDetail' => $queueDetail,
            'pendingJobs' => $pendingJobs,
            'oldestJob' => $oldestJob,
            'pendingOutbox' => $pendingOutbox,
            'oldestOutbox' => $oldestOutbox,
            'failedJobs' => $failedJobs,
            'oldestFailed' => $oldestFailed,
            'schedulerStatus' => $schedulerStatus,
            'schedulerDetail' => $schedulerDetail,
            'staleDevices' => Device::query()->whereNull('revoked_at')->where(fn ($query) => $query->whereNull('last_heartbeat_at')->orWhere('last_heartbeat_at', '<', now()->subMinutes(5)))->count(),
        ]);
    }

    private function redisQueueDetail(): string
    {
        try {
            Redis::connection()->ping();

            return 'اتصال الطابور هو Redis، والاستجابة نجحت.';
        } catch (Throwable) {
            return 'اتصال الطابور هو Redis، وتعذر التحقق منه.';
        }
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
