<!doctype html>
<html lang="en" class="h-full">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex, nofollow, noarchive, nosnippet, noimageindex">
  <link rel="icon" type="image/x-icon" href="{{ asset('favicon.ico') }}">
  <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('apple-touch-icon.png') }}">
  <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
  <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
  <link rel="manifest" href="{{ asset('site.webmanifest') }}">
  <title>Login - VerifySky Control Plane</title>
  @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-full bg-[#171C26] text-[#D7E1F5]">
  <main class="mx-auto grid min-h-screen max-w-6xl place-items-center px-4">
    <form class="w-full max-w-md rounded-lg border border-white/10 bg-[#202632] p-7 shadow-2xl backdrop-blur" method="POST" action="{{ $loginAction ?? url()->current() }}">
    @csrf
      <div class="mb-5">
        <img src="{{ asset('Logo.png') }}" alt="VerifySky" class="mb-5 h-auto w-24 object-contain">
        <p class="text-xs uppercase tracking-[0.2em] text-[#FCB900]">{{ ($loginContext ?? 'admin') === 'tenant' ? 'Account Portal' : 'Control Panel' }}</p>
        <h1 class="mt-2 text-2xl font-bold text-[#FFFFFF]">{{ ($loginContext ?? 'admin') === 'tenant' && isset($tenant) ? $tenant->name : 'VerifySky Dashboard' }}</h1>
      </div>
      <label class="mb-1 block text-sm text-[#D7E1F5]">Username</label>
      <input class="es-input mb-4" name="username" value="{{ old('username') }}" required>
      <label class="mb-1 block text-sm text-[#D7E1F5]">Password</label>
      <input class="es-input" type="password" name="password" required>
      <button class="es-btn mt-5 w-full" type="submit">Sign In</button>
    @if($errors->has('credentials'))
      <div class="mt-4 rounded-xl border border-rose-500/50 bg-rose-500/10 px-3 py-2 text-sm text-rose-200">{{ $errors->first('credentials') }}</div>
    @endif
    </form>
  </main>
</body>
</html>
