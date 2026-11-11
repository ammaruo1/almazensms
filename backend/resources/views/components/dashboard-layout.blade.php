<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'لوحة التحكم' }} · ALMAZEN SMS</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-50 font-sans text-slate-900 antialiased">
    <div class="min-h-screen lg:flex">
        <aside class="bg-slate-950 px-5 py-6 text-white lg:sticky lg:top-0 lg:h-screen lg:w-72 lg:shrink-0">
            <a href="{{ route('admin.index') }}" class="flex items-center gap-3">
                <span class="grid size-11 place-items-center rounded-2xl bg-emerald-400 font-black text-slate-950">A</span>
                <span><span class="block text-lg font-bold tracking-wide">ALMAZEN</span><span class="text-xs text-slate-400">SMS Gateway</span></span>
            </a>
            <nav class="mt-9 grid grid-cols-2 gap-2 text-sm lg:grid-cols-1">
                @php($links = [
                    ['admin.index', 'الرئيسية', '⌂'], ['admin.projects', 'المشاريع والحصص', '▦'], ['admin.users', 'المستخدمون', '♙'],
                    ['admin.devices', 'الأجهزة', '▣'], ['admin.keys', 'مفاتيح API', '⌘'], ['admin.messages', 'الرسائل', '≡'],
                    ['admin.usage', 'الاستخدام', '▥'], ['admin.audit', 'سجل التدقيق', '◷'], ['admin.diagnostics', 'التشخيص', '◇'],
                ])
                @foreach ($links as [$routeName, $label, $icon])
                    <a href="{{ route($routeName) }}" class="flex items-center gap-3 rounded-xl px-3 py-2.5 transition {{ request()->routeIs($routeName) ? 'bg-white/10 text-emerald-300' : 'text-slate-300 hover:bg-white/5 hover:text-white' }}">
                        <span class="w-5 text-center text-lg">{{ $icon }}</span><span>{{ $label }}</span>
                    </a>
                @endforeach
            </nav>
            <div class="mt-8 hidden rounded-2xl border border-white/10 bg-white/5 p-4 text-xs leading-6 text-slate-300 lg:block">
                <span class="mb-2 block font-semibold text-emerald-300">تشغيل مسؤول</span>
                حالات الإرسال تعكس آخر إشارة مؤكدة من الجهاز والشبكة، ولا تعني دائماً وصول الرسالة للمستلم.
            </div>
            <form method="POST" action="{{ route('admin.logout') }}" class="mt-6">
                @csrf
                <button class="w-full rounded-xl border border-white/10 px-4 py-2.5 text-sm text-slate-300 hover:bg-white/5">تسجيل الخروج</button>
            </form>
        </aside>
        <main class="min-w-0 flex-1 px-4 py-6 sm:px-7 lg:px-10 lg:py-9">
            <header class="mb-8 flex flex-wrap items-center justify-between gap-4">
                <div><p class="text-xs font-semibold uppercase tracking-[.2em] text-emerald-700">ALMAZEN SMS / OPERATIONS</p><h1 class="mt-2 text-2xl font-bold tracking-tight sm:text-3xl">{{ $title ?? 'لوحة التحكم' }}</h1></div>
                <div class="flex items-center gap-3 rounded-full border border-slate-200 bg-white px-4 py-2 text-sm shadow-sm"><span class="size-2 rounded-full bg-emerald-500"></span><span>{{ auth()->user()->name }}</span><span class="text-xs text-slate-400">مدير</span></div>
            </header>
            @if (session('status'))
                <div class="mb-6 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">{{ session('status') }}</div>
            @endif
            @if ($errors->any())
                <div class="mb-6 rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-900"><p class="font-semibold">تعذر حفظ البيانات</p><ul class="mt-1 list-inside list-disc">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
            @endif
            {{ $slot }}
        </main>
    </div>
</body>
</html>
