<x-dashboard-layout title="مفاتيح API والحصص">
    <div class="mb-6 rounded-2xl border border-slate-200 bg-white p-5 text-sm leading-7 text-slate-600 shadow-sm">
        <h2 class="font-bold text-slate-900">كيف تُحسب الحصة الفعالة</h2>
        <p class="mt-2">لكل مفتاح حد يومي وحد طلبات في الدقيقة. سقف المشروع اليومي حد إضافي مشترك بين مفاتيح المشروع. المتبقي الفعّال لليوم = الأقل بين متبقي المفتاح ومتبقي سقف المشروع. إعادة الطلب بنفس Idempotency-Key لا تخصم الحصة مرة ثانية. الخادم هو الذي يفرض هذه الحدود.</p>
    </div>
    <div class="grid gap-6 xl:grid-cols-[.85fr_1.6fr]">
        <section class="h-fit rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <h2 class="font-bold">مفتاح جديد</h2>
            <p class="mt-2 text-sm leading-6 text-slate-500">يُحفظ المفتاح مشفّراً ويمكن للمدير كشفه لاحقاً من البطاقة. المفاتيح القديمة التي أُنشئت قبل ذلك لا تُسترجع. حدّد حصة هذا المفتاح من هنا.</p>
            <form method="POST" action="#" id="key-form" class="mt-5 space-y-4">
                @csrf
                <label class="block text-sm font-medium">المشروع
                    <select name="project_id" required class="form-control">
                        @foreach ($projects as $project)
                            <option value="{{ $project->id }}">{{ $project->name }} · سقف {{ $project->daily_message_limit }}/يوم</option>
                        @endforeach
                    </select>
                </label>
                <label class="block text-sm font-medium">اسم المفتاح<input name="name" required maxlength="80" placeholder="خادم الإنتاج" class="form-control"></label>
                <label class="block text-sm font-medium">الحد اليومي لهذا المفتاح<input name="daily_message_limit" type="number" min="1" max="1000" value="20" required class="form-control"></label>
                <label class="block text-sm font-medium">الطلبات في الدقيقة<input name="requests_per_minute" type="number" min="1" max="120" value="10" required class="form-control"></label>
                <button class="button-primary">إنشاء مفتاح API</button>
            </form>
            <script>document.getElementById('key-form').addEventListener('submit', function (event) { event.preventDefault(); const project = this.elements.project_id.value; this.action = `/admin/projects/${encodeURIComponent(project)}/keys`; this.submit(); });</script>
        </section>
        <section class="space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="font-bold">المفاتيح الحالية</h2>
                <a href="{{ route('admin.keys.guide') }}" class="inline-flex rounded-xl bg-slate-950 px-4 py-2.5 text-sm font-semibold text-white hover:bg-slate-800">تنزيل دليل التعامل مع الخدمات</a>
            </div>
            <form method="GET" action="{{ route('admin.keys') }}" class="grid gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:grid-cols-4">
                <label class="block text-xs font-medium text-slate-600">بحث بالاسم أو البادئة<input name="q" value="{{ $filters['q'] }}" maxlength="80" class="form-control" placeholder="خادم الإنتاج"></label>
                <label class="block text-xs font-medium text-slate-600">المشروع
                    <select name="project_id" class="form-control">
                        <option value="">كل المشاريع</option>
                        @foreach ($filterProjects as $project)
                            <option value="{{ $project->id }}" @selected((string) $filters['project_id'] === (string) $project->id)>{{ $project->name }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="block text-xs font-medium text-slate-600">الحالة
                    <select name="status" class="form-control">
                        <option value="">الكل</option>
                        <option value="active" @selected($filters['status'] === 'active')>نشط</option>
                        <option value="revoked" @selected($filters['status'] === 'revoked')>ملغى</option>
                    </select>
                </label>
                <button class="button-secondary self-end">تصفية</button>
            </form>
            @forelse ($keys as $key)
                @php
                    $keyUsed = (int) ($key->dailyUsage->first()?->message_count ?? 0);
                    $projectUsed = (int) ($key->project?->dailyUsage->first()?->message_count ?? 0);
                    $keyRemaining = max(0, (int) $key->daily_message_limit - $keyUsed);
                    $projectRemaining = max(0, (int) ($key->project->daily_message_limit ?? 0) - $projectUsed);
                    $effective = \App\Services\EffectiveQuota::dailyRemaining((int) $key->daily_message_limit, $keyUsed, (int) ($key->project->daily_message_limit ?? 0), $projectUsed);
                @endphp
                <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h2 class="font-bold">{{ $key->name }}</h2>
                            <p class="mt-1 font-mono text-xs text-slate-500">{{ $key->prefix }}… · {{ $key->project?->name }}</p>
                        </div>
                        @if ($key->revoked_at)
                            <span class="rounded-full bg-slate-100 px-3 py-1 text-xs text-slate-600">ملغى</span>
                        @else
                            <span class="rounded-full bg-emerald-50 px-3 py-1 text-xs text-emerald-800">نشط</span>
                        @endif
                    </div>
                    <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-3">
                        <div><dt class="text-xs text-slate-500">مستخدم اليوم (UTC {{ $today }})</dt><dd class="font-semibold tabular-nums">{{ number_format($keyUsed) }}</dd></div>
                        <div><dt class="text-xs text-slate-500">متبقي المفتاح</dt><dd class="font-semibold tabular-nums">{{ number_format($keyRemaining) }}</dd></div>
                        <div><dt class="text-xs text-slate-500">متبقي سقف المشروع</dt><dd class="font-semibold tabular-nums">{{ number_format($projectRemaining) }}</dd></div>
                        <div class="sm:col-span-3"><dt class="text-xs text-slate-500">الحصة الفعالة المتبقية</dt><dd class="text-lg font-bold tabular-nums text-emerald-800">{{ number_format($effective) }}</dd></div>
                    </dl>
                    <p class="mt-2 text-xs text-slate-400">آخر طلب: {{ $key->last_used_at?->diffForHumans() ?? '—' }}</p>
                    @unless ($key->revoked_at)
                        <form method="POST" action="{{ route('admin.keys.update', [$key->project, $key]) }}" class="mt-4 grid gap-3 border-t border-slate-100 pt-4 sm:grid-cols-2 lg:grid-cols-4">
                            @csrf @method('PATCH')
                            <label class="text-xs font-medium text-slate-600">اسم المفتاح<input name="name" value="{{ $key->name }}" required maxlength="80" class="form-control"></label>
                            <label class="text-xs font-medium text-slate-600">الحد اليومي<input name="daily_message_limit" type="number" min="1" max="1000" value="{{ $key->daily_message_limit }}" required class="form-control"></label>
                            <label class="text-xs font-medium text-slate-600">في الدقيقة<input name="requests_per_minute" type="number" min="1" max="120" value="{{ $key->requests_per_minute }}" required class="form-control"></label>
                            <button class="button-secondary self-end">حفظ</button>
                        </form>
                        <div class="mt-3 flex flex-wrap items-center gap-4">
                            @if (filled($key->secret_encrypted))
                                <form method="POST" action="{{ route('admin.keys.reveal', [$key->project, $key]) }}">
                                    @csrf
                                    <button class="text-xs font-semibold text-emerald-800">كشف المفتاح</button>
                                </form>
                            @else
                                <p class="text-xs text-amber-800">لا يمكن استرجاع هذا المفتاح لأنه أُنشئ قبل حفظ النسخة المشفّرة.</p>
                            @endif
                            <form method="POST" action="{{ route('admin.keys.revoke', [$key->project, $key]) }}" onsubmit="return confirm('إلغاء المفتاح فوراً؟')">
                                @csrf @method('DELETE')
                                <button class="text-xs text-rose-700">إلغاء المفتاح</button>
                            </form>
                        </div>
                    @else
                        <div class="mt-3">
                            @if (filled($key->secret_encrypted))
                                <form method="POST" action="{{ route('admin.keys.reveal', [$key->project, $key]) }}">
                                    @csrf
                                    <button class="text-xs font-semibold text-emerald-800">كشف المفتاح الملغى</button>
                                </form>
                            @else
                                <p class="text-xs text-amber-800">لا يمكن استرجاع هذا المفتاح لأنه أُنشئ قبل حفظ النسخة المشفّرة.</p>
                            @endif
                        </div>
                    @endunless
                </article>
            @empty
                <div class="rounded-2xl border border-dashed border-slate-300 bg-white p-10 text-center text-slate-500">لا توجد مفاتيح.</div>
            @endforelse
            {{ $keys->links() }}
        </section>
    </div>
</x-dashboard-layout>
