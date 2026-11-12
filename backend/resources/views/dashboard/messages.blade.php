<x-dashboard-layout title="الرسائل">
    <section class="mb-5 rounded-2xl border border-amber-200 bg-amber-50 p-5 shadow-sm">
        <h2 class="text-base font-bold text-amber-950">إرسال رسالة اختبار حقيقية</h2>
        <p class="mt-1 text-sm leading-6 text-amber-900">ستُرسل SMS فعلية وقد تُحتسب من رصيد الشريحة وحصة المشروع. استخدم رقمك أو رقماً لديك موافقة صاحبه. تُرسل الرسالة النصية الثابتة: «رسالة اختبار من ALMAZEN SMS.»</p>
        <form method="POST" action="{{ route('admin.messages.test-send') }}" class="mt-4 grid gap-3 md:grid-cols-2">
            @csrf
            <label class="text-xs font-semibold text-slate-600">المشروع
                <select name="project_id" required class="form-control">
                    <option value="">اختر المشروع</option>
                    @foreach ($projects->where('status', 'active') as $project)
                        <option value="{{ $project->id }}" @selected((string) old('project_id') === (string) $project->id)>{{ $project->name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="text-xs font-semibold text-slate-600">رقم المستلم بصيغة دولية (+E.164)
                <input name="destination" type="tel" inputmode="tel" autocomplete="tel" dir="ltr" required value="{{ old('destination') }}" placeholder="+9677xxxxxxxx" pattern="\+[1-9][0-9]{7,14}" class="form-control font-mono">
            </label>
            <label class="flex items-start gap-2 text-sm text-amber-950 md:col-span-2">
                <input type="checkbox" name="confirm_recipient" value="1" required class="mt-1 rounded border-amber-400">
                <span>أؤكد أن الرقم لي أو أن صاحبه وافق على استلام رسالة الاختبار، وأوافق على إرسال SMS فعلية الآن.</span>
            </label>
            <button class="button-primary md:col-span-2">إرسال SMS اختبار واحدة</button>
        </form>
        @if ($errors->any())
            <ul class="mt-3 list-inside list-disc text-sm text-red-700">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        @endif
    </section>
    <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
        <form method="GET" action="{{ route('admin.messages') }}" class="grid gap-3 border-b border-slate-100 p-5 sm:grid-cols-2 xl:grid-cols-[1.2fr_1fr_1fr_auto]">
            <label class="text-xs font-semibold text-slate-500">معرّف الرسالة<input name="q" value="{{ request('q') }}" placeholder="ابحث بجزء من UUID" class="form-control font-mono"></label>
            <label class="text-xs font-semibold text-slate-500">المشروع<select name="project_id" class="form-control"><option value="">كل المشاريع</option>@foreach ($projects as $project)<option value="{{ $project->id }}" @selected((string) request('project_id') === (string) $project->id)>{{ $project->name }}</option>@endforeach</select></label>
            <label class="text-xs font-semibold text-slate-500">الحالة<select name="status" class="form-control"><option value="">كل الحالات</option>@foreach ($statuses as $status)<option value="{{ $status }}" @selected(request('status') === $status)>{{ $status }}</option>@endforeach</select></label>
            <button class="button-primary self-end !w-auto">تطبيق البحث</button>
        </form>
        <div class="border-b border-slate-100 bg-amber-50 px-5 py-3 text-xs leading-5 text-amber-900">تظهر أرقام المستلمين ونصوص الرسائل كاملة للمشرفين المسجلين. تبقى الرسائل مشفرة في قاعدة البيانات. لا تعِد إرسال حالة UNKNOWN تلقائياً.</div>
        <div class="overflow-x-auto"><table class="w-full min-w-[1200px] text-right text-sm"><thead class="bg-slate-50 text-xs text-slate-500"><tr><th class="px-5 py-3">المعرّف</th><th class="px-4 py-3">رقم المستلم</th><th class="px-4 py-3">نص الرسالة</th><th class="px-4 py-3">المشروع</th><th class="px-4 py-3">الجهاز</th><th class="px-4 py-3">الحالة</th><th class="px-4 py-3">المحاولات</th><th class="px-5 py-3">التوقيت</th></tr></thead><tbody class="divide-y divide-slate-100">@forelse ($messages as $message)<tr><td class="px-5 py-4 font-mono text-xs">{{ $message->id }}</td><td class="px-4 py-4 font-mono text-xs" dir="ltr">{{ $message->destination }}</td><td class="max-w-sm whitespace-pre-wrap px-4 py-4 text-sm">{{ $message->body }}</td><td class="px-4 py-4">{{ $message->project?->name }}</td><td class="px-4 py-4 text-xs text-slate-600">{{ $message->device?->name ?? '—' }}</td><td class="px-4 py-4"><span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs">{{ $message->status->value }}</span></td><td class="px-4 py-4 tabular-nums">{{ $message->attempt_count }}</td><td class="px-5 py-4 text-xs text-slate-500">{{ $message->queued_at?->format('Y-m-d H:i:s') }}@if ($message->completed_at)<br>انتهت: {{ $message->completed_at->format('Y-m-d H:i:s') }}@endif</td></tr>@empty<tr><td colspan="8" class="px-5 py-10 text-center text-slate-500">لا توجد نتائج مطابقة.</td></tr>@endforelse</tbody></table></div><div class="p-5">{{ $messages->links() }}</div>
    </section>
</x-dashboard-layout>
