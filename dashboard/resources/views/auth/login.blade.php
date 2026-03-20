<!doctype html>
<html lang="en" class="h-full">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Login - Edge Shield Admin</title>
  @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-full bg-gradient-to-br from-slate-950 via-slate-900 to-sky-950 text-slate-100">
  <main class="mx-auto grid min-h-screen max-w-6xl place-items-center px-4">
    <form class="w-full max-w-md rounded-2xl border border-slate-700/70 bg-slate-900/80 p-7 shadow-2xl backdrop-blur" method="POST" action="{{ route('login.submit') }}">
    @csrf
      <div class="mb-5">
        <p class="text-xs uppercase tracking-[0.2em] text-sky-300">Control Panel</p>
        <h1 class="mt-2 text-2xl font-bold">Edge Shield Dashboard</h1>
      </div>
      <label class="mb-1 block text-sm text-slate-300">Username</label>
      <input class="mb-4 w-full rounded-xl border border-slate-700 bg-slate-800 px-3 py-2.5 text-slate-100 outline-none ring-sky-400 transition focus:ring" name="username" value="{{ old('username') }}" required>
      <label class="mb-1 block text-sm text-slate-300">Password</label>
      <input class="w-full rounded-xl border border-slate-700 bg-slate-800 px-3 py-2.5 text-slate-100 outline-none ring-sky-400 transition focus:ring" type="password" name="password" required>
      <button class="mt-5 w-full rounded-xl bg-sky-500 px-3 py-2.5 text-sm font-semibold text-white transition hover:bg-sky-400" type="submit">Sign In</button>
    @if($errors->has('credentials'))
      <div class="mt-4 rounded-xl border border-rose-500/50 bg-rose-500/10 px-3 py-2 text-sm text-rose-200">{{ $errors->first('credentials') }}</div>
    @endif
    </form>
  </main>
</body>
</html>
