<!doctype html>
<html lang="en" class="h-full">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex, nofollow, noarchive, nosnippet, noimageindex">
  <link rel="icon" type="image/x-icon" href="{{ asset('favicon.ico') }}">
  <title>{{ $title ?? 'VerifySky Admin Command Center' }}</title>
  @vite(['resources/css/app.css', 'resources/js/app.js'])
  <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
</head>
@php
  $safeSessionError = session('error') !== null
      ? \App\Support\UserFacingErrorSanitizer::sanitize((string) session('error'))
      : null;
@endphp
<body class="es-body" x-data="{ navOpen: false }">
  @if(session('status') || $safeSessionError || $errors->any())
    <div class="mx-auto max-w-7xl px-4 pt-4 lg:pl-80">
      <div class="rounded-lg border {{ $safeSessionError || $errors->any() ? 'border-rose-400/35 bg-rose-500/15 text-rose-100' : 'border-emerald-400/35 bg-emerald-500/15 text-emerald-100' }} px-4 py-3 text-sm">
        @if(session('status'))
          {{ session('status') }}
        @elseif($safeSessionError)
          {{ $safeSessionError }}
        @else
          {{ implode(' ', $errors->all()) }}
        @endif
      </div>
    </div>
  @endif

  <div class="relative z-10 flex min-h-screen">
    <div x-show="navOpen" x-on:click="navOpen = false" class="fixed inset-0 z-30 bg-black/70 backdrop-blur-sm lg:hidden" style="display:none;"></div>

    @include('partials.sidebar-admin')

    <main class="min-w-0 flex-1 px-4 py-5 lg:ml-80 lg:px-8">
      <div class="mb-5 flex items-center justify-between lg:hidden">
        <button type="button" class="es-btn es-btn-secondary" x-on:click="navOpen = true">Menu</button>
        <img src="{{ asset('Logo.png') }}" alt="VerifySky" class="h-8 w-auto">
      </div>
      @yield('content')
    </main>
  </div>
</body>
</html>
