<x-dashboard-layout title="المشاريع والحصص">
    <div class="grid gap-6 xl:grid-cols-[.85fr_1.6fr]">
        <section class="h-fit rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><h2 class="font-bold">مشروع جديد</h2><form method="POST" action="{{ route('admin.projects.store') }}" class="mt-5 space-y-4">@csrf
            <label class="block text-sm font-medium">اسم المشروع<input name="name" required maxlength="120" class="form-control"></label>
            <label class="block text-sm font-medium">المالك<select name="owner_id" required class="form-control">@foreach ($owners as $owner)<option value="{{ $owner->id }}">{{ $owner->name }} · {{ $owner->email }}</option>@endforeach</select></label>
            <label class="block text-sm font-medium">حد الرسائل اليومي<input name="daily_message_limit" type="number" min="1" max="1000" value="20" required class="form-control"></label>
            <button class="button-primary">إنشاء المشروع</button>
        </form></section>
        <section class="space-y-4">@forelse ($projects as $project)
            <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><div class="flex flex-wrap items-start justify-between gap-3"><div><h2 class="font-bold">{{ $project->name }}</h2><p class="mt-1 text-xs text-slate-500">{{ $project->owner?->name }} · {{ $project->owner?->email }}</p></div><span class="rounded-full px-3 py-1 text-xs {{ $project->status === 'active' ? 'bg-emerald-50 text-emerald-800' : 'bg-slate-100 text-slate-600' }}">{{ $project->status }}</span></div>
                <div class="mt-4 flex flex-wrap gap-x-5 gap-y-2 text-xs text-slate-500"><span>{{ $project->devices_count }} أجهزة</span><span>{{ $project->api_keys_count }} مفاتيح</span><span>{{ $project->messages_count }} رسائل</span></div>
                <form method="POST" action="{{ route('admin.projects.update', $project) }}" class="mt-5 grid gap-3 border-t border-slate-100 pt-4 sm:grid-cols-2">@csrf @method('PATCH')
                    <input name="name" value="{{ $project->name }}" required class="form-control" aria-label="اسم المشروع">
                    <select name="owner_id" required class="form-control" aria-label="المالك">@foreach ($owners as $owner)<option value="{{ $owner->id }}" @selected($project->owner_id === $owner->id)>{{ $owner->name }}</option>@endforeach</select>
                    <input name="daily_message_limit" type="number" min="1" max="1000" value="{{ $project->daily_message_limit }}" required class="form-control" aria-label="حد الرسائل اليومي">
                    <select name="status" class="form-control" aria-label="حالة المشروع"><option value="active" @selected($project->status === 'active')>نشط</option><option value="paused" @selected($project->status === 'paused')>متوقف</option></select>
                    <button class="button-secondary sm:col-span-2">حفظ تعديلات المشروع والحصة</button>
                </form>
            </article>
        @empty<div class="rounded-2xl border border-dashed border-slate-300 bg-white p-10 text-center text-slate-500">لا توجد مشاريع. أنشئ أول مشروع للبدء.</div>@endforelse
        {{ $projects->links() }}</section>
    </div>
</x-dashboard-layout>
