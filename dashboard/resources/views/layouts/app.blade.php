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
  <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
</head>
<body class="min-h-full text-slate-100" x-data="{ isNavigating: false }" x-on:beforeunload.window="isNavigating = true" x-on:pageshow.window="if ($event.persisted) isNavigating = false">

  <!-- Global Premium Loading Overlay -->
  <div x-show="isNavigating" x-transition.opacity.duration.300ms style="display: none;" class="fixed inset-0 z-[9999] flex items-center justify-center bg-slate-950/80 backdrop-blur-md">
    <div class="flex flex-col items-center gap-5">
      <div class="relative flex h-20 w-20 items-center justify-center">
        <div class="absolute inset-0 rounded-full border-t-2 border-sky-400 animate-[spin_1s_linear_infinite]"></div>
        <div class="absolute inset-2 rounded-full border-r-2 border-rose-400 animate-[spin_1.5s_linear_infinite_reverse]"></div>
        <div class="absolute inset-4 rounded-full border-b-2 border-emerald-400 animate-[spin_2s_linear_infinite]"></div>
      </div>
      <div class="text-xs font-bold uppercase tracking-widest text-sky-200 animate-pulse">Processing...</div>
    </div>
  </div>

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
      @php
          $navItems = [
            ['route' => 'dashboard', 'icon' => 'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6', 'label' => 'Dashboard'],
            ['route' => 'domains.index', 'icon' => 'M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9', 'label' => 'Domains Mgmt'],
            ['route' => 'firewall.index', 'icon' => 'M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z', 'label' => 'Global Firewall'],
            ['route' => 'sensitive_paths.index', 'icon' => 'M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z', 'label' => 'Sensitive Paths'],
            ['route' => 'trap_network.index', 'icon' => 'M13 10V3L4 14h7v7l9-11h-7z', 'label' => 'Trap Network'],
            ['route' => 'logs.index', 'icon' => 'M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z', 'label' => 'Security Logs'],
            ['route' => 'settings.index', 'icon' => 'M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z', 'label' => 'Settings'],
          ];
        @endphp
      <a class="rounded-lg px-2 py-1 text-sm text-slate-300 transition hover:bg-white/10 hover:text-white" href="{{ route('dashboard') }}">Overview</a>
      <a class="rounded-lg px-2 py-1 text-sm text-slate-300 transition hover:bg-white/10 hover:text-white" href="{{ route('domains.index') }}">Domains</a>
      <a class="rounded-lg px-2 py-1 text-sm {{ request()->routeIs('firewall.*') ? 'text-white bg-white/10' : 'text-slate-300' }} transition hover:bg-white/10 hover:text-white" href="{{ route('firewall.index') }}">Global Firewall</a>
      <a class="rounded-lg px-2 py-1 text-sm {{ request()->routeIs('sensitive_paths.*') ? 'text-white bg-white/10' : 'text-slate-300' }} transition hover:bg-rose-500/20 hover:text-rose-200" href="{{ route('sensitive_paths.index') }}">Sensitive Paths</a>
      <a class="rounded-lg px-2 py-1 text-sm text-slate-300 transition hover:bg-white/10 hover:text-white" href="{{ route('logs.index') }}">Logs</a>
      <a class="rounded-lg px-2 py-1 text-sm text-slate-300 transition hover:bg-white/10 hover:text-white" href="{{ route('settings.index') }}">Settings</a>
      <a class="rounded-lg px-2 py-1 text-sm text-slate-300 transition hover:bg-white/10 hover:text-white" href="{{ route('trap_network.index') }}">Trap Leads</a>
      <a class="rounded-lg px-2 py-1 text-sm {{ request()->routeIs('ip_farm.*') ? 'text-rose-200 bg-rose-500/20' : 'text-slate-300' }} transition hover:bg-rose-500/20 hover:text-rose-200" href="{{ route('ip_farm.index') }}">☠️ IP Farm</a>
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
