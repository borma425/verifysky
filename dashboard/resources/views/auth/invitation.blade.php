<!doctype html>
<html lang="en" class="h-full">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex, nofollow, noarchive, nosnippet, noimageindex">
  <link rel="icon" type="image/x-icon" href="{{ asset('favicon.ico') }}">
  <title>Accept Invitation - VerifySky</title>
  @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-full bg-[#171C26] text-[#D7E1F5]">
  <main class="mx-auto grid min-h-screen max-w-6xl place-items-center px-4">
    <form class="w-full max-w-md rounded-lg border border-white/10 bg-[#202632] p-7 shadow-2xl backdrop-blur" method="POST" action="{{ $acceptAction }}">
      @csrf
      <div class="mb-5">
        <img src="{{ asset('Logo.png') }}" alt="VerifySky" class="mb-5 h-auto w-24 object-contain">
        <p class="text-xs uppercase tracking-[0.2em] text-[#FCB900]">Team Invitation</p>
        <h1 class="mt-2 text-2xl font-bold text-[#FFFFFF]">Join {{ $invitation->tenant->name }}</h1>
        <p class="mt-2 text-sm text-[#AEB9CC]">{{ $invitation->email }} was invited as {{ $invitation->role }}.</p>
      </div>

      @if($existingUser)
        <div class="mb-4 rounded-lg border border-[#303540] bg-[#171C26] px-3 py-3 text-sm text-[#AEB9CC]">
          This email already has a VerifySky account. Confirm your password to join this workspace.
        </div>
        <label class="mb-1 block text-sm text-[#D7E1F5]">Password</label>
        <input class="es-input" type="password" name="password" required autocomplete="current-password">
        @error('password')
          <p class="mt-2 text-sm font-semibold text-[#D47B78]">{{ $message }}</p>
        @enderror
      @else
        <label class="mb-1 block text-sm text-[#D7E1F5]">Name</label>
        <input class="es-input mb-4" name="name" value="{{ old('name') }}" required autocomplete="name">
        @error('name')
          <p class="-mt-2 mb-3 text-sm font-semibold text-[#D47B78]">{{ $message }}</p>
        @enderror

        <label class="mb-1 block text-sm text-[#D7E1F5]">Password</label>
        <input class="es-input mb-4" type="password" name="password" required autocomplete="new-password">
        @error('password')
          <p class="-mt-2 mb-3 text-sm font-semibold text-[#D47B78]">{{ $message }}</p>
        @enderror

        <label class="mb-1 block text-sm text-[#D7E1F5]">Confirm Password</label>
        <input class="es-input" type="password" name="password_confirmation" required autocomplete="new-password">
      @endif

      <button class="es-btn mt-5 w-full" type="submit">Accept Invitation</button>
    </form>
  </main>
</body>
</html>
