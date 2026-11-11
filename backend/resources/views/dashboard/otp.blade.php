<x-dashboard-layout title="تحقق OTP">
    <div class="mb-6 rounded-2xl border border-amber-200 bg-amber-50 p-5 text-sm leading-7 text-amber-950">
        OTP متوقف افتراضيًا. لا يُفعّل إلا من هذه الصفحة. الرمز لا يُعرض هنا ولا يُحفظ كنص واضح، ورقم الهاتف يظهر مقنّعًا. طلب الرمز يمر من مسار الرسائل الحالي ويخصم حصة مفتاح API.
    </div>
    <section class="space-y-4">
        @forelse ($projects as $project)
            <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h2 class="font-bold">{{ $project->name }}</h2>
                    <span class="rounded-full px-3 py-1 text-xs {{ $project->otp_enabled ? 'bg-emerald-50 text-emerald-800' : 'bg-slate-100 text-slate-600' }}">{{ $project->otp_enabled ? 'مفعّل' : 'متوقف' }}</span>
                </div>
                <form method="POST" action="{{ route('admin.otp.update', $project) }}" class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                    @csrf @method('PATCH')
                    <input type="hidden" name="otp_enabled" value="0">
                    <label class="flex items-center gap-2 text-sm font-medium sm:col-span-2 lg:col-span-1"><input type="checkbox" name="otp_enabled" value="1" @checked($project->otp_enabled)> تفعيل OTP</label>
                    <label class="text-xs font-medium text-slate-600">الصلاحية بالثواني<input name="otp_ttl_seconds" type="number" min="60" max="1800" value="{{ $project->otp_ttl_seconds }}" required class="form-control"></label>
                    <label class="text-xs font-medium text-slate-600">حد المحاولات<input name="otp_max_attempts" type="number" min="1" max="10" value="{{ $project->otp_max_attempts }}" required class="form-control"></label>
                    <label class="text-xs font-medium text-slate-600">مهلة إعادة الإرسال<input name="otp_resend_cooldown_seconds" type="number" min="15" max="3600" value="{{ $project->otp_resend_cooldown_seconds }}" required class="form-control"></label>
                    <button class="button-secondary self-end">حفظ إعدادات OTP</button>
                </form>
            </article>
        @empty
            <div class="rounded-2xl border border-dashed border-slate-300 bg-white p-10 text-center text-slate-500">لا توجد مشاريع.</div>
        @endforelse
    </section>
    <section class="mt-6 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
        <div class="border-b border-slate-100 px-5 py-4"><h2 class="font-bold">حالات التحديات</h2><p class="mt-1 text-xs text-slate-500">بدون الرقم الكامل، وبدون الرمز، وبدون نص الرسالة.</p></div>
        <div class="overflow-x-auto">
            <table class="w-full min-w-[760px] text-right text-sm">
                <thead class="bg-slate-50 text-xs text-slate-500"><tr><th class="px-5 py-3">المشروع</th><th class="px-4 py-3">الهاتف</th><th class="px-4 py-3">الغرض</th><th class="px-4 py-3">الحالة</th><th class="px-4 py-3">المحاولات</th><th class="px-5 py-3">الانتهاء</th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($challenges as $challenge)
                        @php($label = ['pending' => 'بانتظار التحقق', 'verified' => 'تم التحقق', 'expired' => 'منتهٍ', 'exhausted' => 'استُنفدت المحاولات', 'superseded' => 'استُبدل'][$challenge->status] ?? $challenge->status)
                        <tr>
                            <td class="px-5 py-4">{{ $challenge->project?->name }}</td>
                            <td class="px-4 py-4 font-mono text-xs" dir="ltr">{{ $challenge->phone_mask }}</td>
                            <td class="px-4 py-4">{{ $challenge->purpose }}</td>
                            <td class="px-4 py-4">{{ $label }}</td>
                            <td class="px-4 py-4 tabular-nums">{{ $challenge->attempts }}/{{ $challenge->max_attempts }}</td>
                            <td class="px-5 py-4 text-xs text-slate-500">{{ $challenge->expires_at?->format('Y-m-d H:i') }}<br>{{ $challenge->created_at?->format('Y-m-d H:i') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-5 py-10 text-center text-slate-500">لا توجد تحديات OTP.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="p-5">{{ $challenges->links() }}</div>
    </section>
</x-dashboard-layout>
