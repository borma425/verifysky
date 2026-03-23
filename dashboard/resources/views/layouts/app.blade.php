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
<body class="min-h-full text-slate-100">
  <div class="pointer-events-none fixed inset-0 overflow-hidden">
    <div class="absolute -left-36 -top-24 h-80 w-80 rounded-full bg-cyan-400/15 blur-3xl"></div>
    <div class="absolute -right-36 top-10 h-96 w-96 rounded-full bg-sky-500/15 blur-3xl"></div>
  </div>

  <div class="relative z-10 border-b border-white/10 bg-slate-950/65 text-slate-100 backdrop-blur-xl">
    <div class="mx-auto flex max-w-7xl flex-wrap items-center gap-3 px-4 py-3 md:gap-5 md:py-4">
      <a href="{{ route('dashboard') }}" class="mr-2 flex items-center gap-3 md:gap-4">
        <img src="{{ asset('Logo.png') }}" alt="Edge Shield" class="h-14 w-14 rounded-xl border border-sky-400/40 bg-slate-900/80 object-cover shadow-lg shadow-sky-500/30 md:h-16 md:w-16">
        <span class="text-base font-extrabold tracking-wide text-sky-200 md:text-lg">Edge Shield Admin</span>
      </a>
      <a class="rounded-lg px-2 py-1 text-sm text-slate-300 transition hover:bg-white/10 hover:text-white" href="{{ route('dashboard') }}">Overview</a>
      <a class="rounded-lg px-2 py-1 text-sm text-slate-300 transition hover:bg-white/10 hover:text-white" href="{{ route('domains.index') }}">Domains</a>
      <a class="rounded-lg px-2 py-1 text-sm text-slate-300 transition hover:bg-white/10 hover:text-white" href="{{ route('logs.index') }}">Logs</a>
      <a class="rounded-lg px-2 py-1 text-sm text-slate-300 transition hover:bg-white/10 hover:text-white" href="{{ route('settings.index') }}">Settings</a>
      <a class="rounded-lg px-2 py-1 text-sm text-slate-300 transition hover:bg-white/10 hover:text-white" href="{{ route('trap_network.index') }}">Trap Leads</a>
      <div class="flex-1"></div>
      <form method="POST" action="{{ route('logout') }}">
        @csrf
        <button class="es-btn es-btn-secondary" type="submit">Logout</button>
      </form>
    </div>
  </div>

  <div class="relative z-10 mx-auto max-w-7xl px-4 py-6 md:py-8">
    @if(session('status'))
      <div class="mb-4 es-card border-emerald-400/30 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-200">{{ session('status') }}</div>
    @endif
    @if(session('error'))
      <div class="mb-4 es-card border-rose-400/30 bg-rose-500/10 px-4 py-3 text-sm text-rose-200">{{ session('error') }}</div>
    @endif
    @yield('content')
  </div>
</body>
</html>
