<x-dashboard-layout title="الاستخدام والحصص">
    <p class="mb-6 text-sm leading-7 text-slate-600">اليوم حسب UTC ({{ $today }}). الحصة الفعالة لكل مفتاح هي الأقل بين متبقي حد المفتاح ومتبقي سقف المشروع. حد الدقيقة يُفرض على المفتاح وحده ولا يُعرض هنا كرقم ثابت من التطبيق.</p>
    <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
        <div class="border-b border-slate-100 px-5 py-4"><h2 class="font-bold">سقف المشاريع اليوم</h2><p class="mt-1 text-xs text-slate-500">حد إضافي مشترك. يمكن تعديله من صفحة المشاريع.</p></div>
        <div class="overflow-x-auto">
            <table class="w-full min-w-[620px] text-right text-sm">
                <thead class="bg-slate-50 text-xs text-slate-500"><tr><th class="px-5 py-3">المشروع</th><th class="px-4 py-3">الحالة</th><th class="px-4 py-3">المستخدم</th><th class="px-4 py-3">السقف</th><th class="px-5 py-3">المتبقي</th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($projects as $project)
                        @php($used = (int) ($project->dailyUsage->first()?->message_count ?? 0))
                        <tr>
                            <td class="px-5 py-4 font-semibold">{{ $project->name }}</td>
                            <td class="px-4 py-4">{{ $project->status }}</td>
                            <td class="px-4 py-4 tabular-nums">{{ number_format($used) }}</td>
                            <td class="px-4 py-4 tabular-nums">{{ number_format($project->daily_message_limit) }}</td>
                            <td class="px-5 py-4 tabular-nums">{{ number_format(max(0, $project->daily_message_limit - $used)) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-5 py-10 text-center text-slate-500">لا توجد مشاريع.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
    <section class="mt-6 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
        <div class="border-b border-slate-100 px-5 py-4"><h2 class="font-bold">حصص مفاتيح API</h2><p class="mt-1 text-xs text-slate-500">البادئة فقط. لا يُعرض المفتاح الكامل.</p></div>
        <div class="overflow-x-auto">
            <table class="w-full min-w-[860px] text-right text-sm">
                <thead class="bg-slate-50 text-xs text-slate-500"><tr><th class="px-5 py-3">المفتاح</th><th class="px-4 py-3">اليوم</th><th class="px-4 py-3">حد المفتاح</th><th class="px-4 py-3">في الدقيقة</th><th class="px-4 py-3">متبقي السقف</th><th class="px-5 py-3">الفعّال</th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($keys as $key)
                        @php
                            $keyUsed = (int) ($key->dailyUsage->first()?->message_count ?? 0);
                            $projectUsed = (int) ($key->project?->dailyUsage->first()?->message_count ?? 0);
                            $projectRemaining = max(0, (int) ($key->project->daily_message_limit ?? 0) - $projectUsed);
                            $effective = \App\Services\EffectiveQuota::dailyRemaining((int) $key->daily_message_limit, $keyUsed, (int) ($key->project->daily_message_limit ?? 0), $projectUsed);
                        @endphp
                        <tr>
                            <td class="px-5 py-4"><p class="font-semibold">{{ $key->name }}</p><p class="mt-1 font-mono text-xs text-slate-500">{{ $key->prefix }}… · {{ $key->project?->name }}</p></td>
                            <td class="px-4 py-4 tabular-nums">{{ number_format($keyUsed) }}</td>
                            <td class="px-4 py-4 tabular-nums">{{ number_format($key->daily_message_limit) }}</td>
                            <td class="px-4 py-4 tabular-nums">{{ number_format($key->requests_per_minute) }}</td>
                            <td class="px-4 py-4 tabular-nums">{{ number_format($projectRemaining) }}</td>
                            <td class="px-5 py-4 font-semibold tabular-nums">{{ number_format($effective) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-5 py-10 text-center text-slate-500">لا توجد مفاتيح.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</x-dashboard-layout>
