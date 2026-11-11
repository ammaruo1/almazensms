<x-dashboard-layout title="نظرة عامة">
    <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ([['المشاريع', $projectCount, 'إجمالي المشاريع المسجلة'], ['الأجهزة النشطة', $deviceCount, 'الأجهزة غير الملغاة'], ['بانتظار الجهاز', $queuedCount, 'رسائل في حالة queued'], ['نتيجة غير محسومة', $unknownCount, 'تحتاج مراجعة قبل أي إعادة محاولة']] as [$label, $value, $hint])
            <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><p class="text-sm text-slate-500">{{ $label }}</p><p class="mt-3 text-3xl font-bold tabular-nums">{{ number_format($value) }}</p><p class="mt-2 text-xs text-slate-400">{{ $hint }}</p></article>
        @endforeach
    </section>
    <section class="mt-7 grid gap-6 xl:grid-cols-[1.6fr_1fr]">
        <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div class="flex items-center justify-between border-b border-slate-100 px-5 py-4"><div><h2 class="font-bold">أحدث الرسائل</h2><p class="mt-1 text-xs text-slate-500">لا نعرض رقم المستلم أو محتوى الرسالة</p></div><a href="{{ route('admin.messages') }}" class="text-sm font-semibold text-emerald-700">كل الرسائل ←</a></div>
            <div class="overflow-x-auto"><table class="w-full text-right text-sm"><thead class="bg-slate-50 text-xs text-slate-500"><tr><th class="px-5 py-3">المعرّف</th><th class="px-4 py-3">المشروع</th><th class="px-4 py-3">الحالة</th><th class="px-5 py-3">أُنشئت</th></tr></thead><tbody class="divide-y divide-slate-100">
                @forelse ($latestMessages as $message)<tr><td class="px-5 py-3 font-mono text-xs text-slate-600">{{ Str::limit($message->id, 15) }}</td><td class="px-4 py-3">{{ $message->project?->name }}</td><td class="px-4 py-3"><span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs">{{ $message->status->value }}</span></td><td class="px-5 py-3 text-xs text-slate-500">{{ $message->queued_at?->format('Y-m-d H:i') }}</td></tr>@empty<tr><td colspan="4" class="px-5 py-10 text-center text-slate-500">لا توجد رسائل بعد.</td></tr>@endforelse
            </tbody></table></div>
        </div>
        <div class="space-y-4">
            <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><div class="flex items-center justify-between"><h2 class="font-bold">صندوق الإرسال الموثوق</h2><span class="rounded-full bg-amber-50 px-3 py-1 text-xs text-amber-800">{{ $outboxPending }} معلّق</span></div><p class="mt-3 text-sm leading-6 text-slate-500">الطلبات المحفوظة تنتظر معالجة الطابور. راجع التشخيص إذا استمر العدد بالارتفاع.</p><a href="{{ route('admin.diagnostics') }}" class="mt-4 inline-block text-sm font-semibold text-emerald-700">فحص الخدمات ←</a></article>
            <article class="rounded-2xl bg-slate-900 p-5 text-white"><p class="text-sm font-semibold text-emerald-300">سلامة الإرسال</p><p class="mt-2 text-sm leading-6 text-slate-300">حالة UNKNOWN لا تُعاد تلقائياً. الحالة sent_to_network لا تثبت وصول الرسالة إلى الهاتف المستلم.</p></article>
        </div>
    </section>
</x-dashboard-layout>
