<!doctype html>
<html lang="en" class="h-full">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>{{ $title ?? 'Edge Shield Dashboard' }}</title>
  @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-full bg-slate-100 text-slate-900">
  <div class="border-b border-slate-800 bg-slate-900 text-slate-100">
    <div class="mx-auto flex max-w-7xl items-center gap-5 px-4 py-3">
      <div class="rounded-md bg-sky-500/20 px-2 py-1 text-sm font-semibold text-sky-300">Edge Shield Admin</div>
      <a class="text-sm text-slate-300 hover:text-white" href="{{ route('dashboard') }}">Overview</a>
      <a class="text-sm text-slate-300 hover:text-white" href="{{ route('domains.index') }}">Domains</a>
      <a class="text-sm text-slate-300 hover:text-white" href="{{ route('logs.index') }}">Logs</a>
      <a class="text-sm text-slate-300 hover:text-white" href="{{ route('settings.index') }}">Settings</a>
      <a class="text-sm text-slate-300 hover:text-white" href="{{ route('actions.index') }}">Actions</a>
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
