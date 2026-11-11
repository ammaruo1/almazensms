<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>تسجيل الدخول · ALMAZEN SMS</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="grid min-h-screen place-items-center bg-slate-950 px-4 py-10 font-sans text-slate-900">
    <main class="w-full max-w-md overflow-hidden rounded-[2rem] bg-white shadow-2xl shadow-black/20">
        <div class="bg-emerald-400 px-8 py-8"><div class="grid size-12 place-items-center rounded-2xl bg-slate-950 text-xl font-black text-white">A</div><p class="mt-6 text-sm font-semibold tracking-[.2em] text-slate-800">ALMAZEN SMS</p><h1 class="mt-2 text-2xl font-bold">بوابة إدارة الرسائل</h1><p class="mt-2 text-sm text-slate-700">دخول آمن للمسؤولين المخوّلين فقط</p></div>
        <form method="POST" action="{{ route('login.store') }}" class="space-y-5 p-8">
            @csrf
            @if ($errors->any())<div class="rounded-xl bg-rose-50 px-4 py-3 text-sm text-rose-800">{{ $errors->first() }}</div>@endif
            <label class="block text-sm font-semibold">البريد الإلكتروني<input name="email" type="email" value="{{ old('email') }}" autocomplete="username" required autofocus class="mt-2 w-full rounded-xl border border-slate-200 px-4 py-3 font-normal outline-none focus:border-emerald-500 focus:ring-4 focus:ring-emerald-100"></label>
            <label class="block text-sm font-semibold">كلمة المرور<input name="password" type="password" autocomplete="current-password" required class="mt-2 w-full rounded-xl border border-slate-200 px-4 py-3 font-normal outline-none focus:border-emerald-500 focus:ring-4 focus:ring-emerald-100"></label>
            <button class="w-full rounded-xl bg-slate-950 px-4 py-3 font-semibold text-white hover:bg-slate-800">دخول إلى اللوحة</button>
            <p class="text-center text-xs leading-5 text-slate-500">لا يوجد تسجيل ذاتي. تُنشأ الحسابات إدارياً، وتُحظر المحاولات المتكررة تلقائياً.</p>
        </form>
    </main>
</body>
</html>
