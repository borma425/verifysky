<!doctype html>
<html lang="en" class="h-full">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex, nofollow, noarchive, nosnippet, noimageindex">
  <link rel="icon" type="image/png" sizes="512x512" href="{{ asset('Logo.png') }}">
  <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('apple-touch-icon.png') }}">
  <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
  <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
  <link rel="manifest" href="{{ asset('site.webmanifest') }}">
  <title>{{ $title ?? 'Edge Shield Dashboard' }}</title>
  @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-full bg-slate-100 text-slate-900">
  <div class="border-b border-slate-800 bg-slate-900 text-slate-100">
    <div class="mx-auto flex max-w-7xl items-center gap-5 px-4 py-3 md:gap-6 md:py-4">
      <a href="{{ route('dashboard') }}" class="mr-2 flex items-center gap-3 md:gap-4">
        <img src="{{ asset('Logo.png') }}" alt="Edge Shield" class="h-14 w-14 rounded-xl border border-sky-400/40 bg-slate-800 object-cover shadow-lg shadow-sky-500/20 md:h-16 md:w-16">
        <span class="text-base font-extrabold tracking-wide text-sky-200 md:text-lg">Edge Shield Admin</span>
      </a>
      <a class="text-sm text-slate-300 hover:text-white" href="{{ route('dashboard') }}">Overview</a>
      <a class="text-sm text-slate-300 hover:text-white" href="{{ route('domains.index') }}">Domains</a>
      <a class="text-sm text-slate-300 hover:text-white" href="{{ route('logs.index') }}">Logs</a>
      <a class="text-sm text-slate-300 hover:text-white" href="{{ route('settings.index') }}">Settings</a>
      <a class="text-sm text-slate-300 hover:text-white" href="{{ route('trap_network.index') }}">real scammers</a>
      <div class="flex-1"></div>
    <form method="POST" action="{{ route('logout') }}">
      @csrf
      <button class="rounded-lg bg-slate-700 px-3 py-1.5 text-sm font-medium hover:bg-slate-600" type="submit">Logout</button>
    </form>
    </div>
  </div>
  <div class="mx-auto max-w-7xl px-4 py-6">
    @if(session('status'))
      <div class="mb-4 rounded-xl border border-emerald-300 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ session('status') }}</div>
    @endif
    @if(session('error'))
      <div class="mb-4 rounded-xl border border-rose-300 bg-rose-50 px-4 py-3 text-sm text-rose-800">{{ session('error') }}</div>
    @endif
    @yield('content')
  </div>
</body>
</html>
