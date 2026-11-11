<x-dashboard-layout title="التشخيص وصحة الخدمات">
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        @foreach ([['قاعدة البيانات', $database, 'اتصال تطبيق Laravel بمخزن البيانات'], ['Redis / Queue', $queue['status'], $queue['detail']], ['صندوق Outbox', $pendingOutbox === null ? 'غير متاح' : number_format($pendingOutbox).' معلّق', 'طلبات محفوظة تنتظر المعالجة'], ['الأجهزة المتأخرة', number_format($staleDevices), 'لا يوجد heartbeat خلال آخر 5 دقائق أو لم يتصل الجهاز'], ['الوظائف الفاشلة', $failedJobs === null ? 'غير متاح' : number_format($failedJobs), 'قراءة جدول failed_jobs إن كان مُهيأً']] as [$label, $value, $detail])
            <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><p class="text-sm text-slate-500">{{ $label }}</p><p class="mt-3 text-xl font-bold">{{ $value }}</p><p class="mt-2 text-xs leading-5 text-slate-400">{{ $detail }}</p></article>
        @endforeach
    </div>
    <div class="mt-6 rounded-2xl border border-slate-200 bg-white p-5 text-sm leading-7 text-slate-600"><h2 class="font-bold text-slate-900">حدود التشخيص</h2><p class="mt-2">هذه الفحوصات لقطة لحظية من الخادم. حالة الجهاز لا تثبت وجود رصيد SMS أو توفر الشبكة الخلوية أو وصول الرسالة. يجب إعداد worker دائم ومراقبة مستقلة ونسخ احتياطية قبل تشغيل الإنتاج.</p></div>
</x-dashboard-layout>
