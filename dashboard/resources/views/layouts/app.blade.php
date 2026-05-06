<!doctype html>
<html lang="en" class="dark h-full">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex, nofollow, noarchive, nosnippet, noimageindex">
  <link rel="icon" type="image/x-icon" href="{{ asset('favicon.ico') }}">
  <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('apple-touch-icon.png') }}">
  <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
  <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
  <link rel="manifest" href="{{ asset('site.webmanifest') }}">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
  <title>{{ $title ?? 'VerifySky Dashboard' }}</title>
  @vite(['resources/css/app.css', 'resources/js/app.js'])
  <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
</head>
@php
  $flashModal = null;
  $safeSessionError = session('error') !== null
      ? \App\Support\UserFacingErrorSanitizer::sanitize((string) session('error'))
      : null;

  if (session('status')) {
      $flashModal = [
          'type' => 'success',
          'title' => 'Done',
          'message' => session('status'),
          'icon' => 'circle-check.svg',
          'action_label' => 'OK',
          'helper_text' => null,
          'action_event' => null,
      ];
  } elseif ($safeSessionError !== null) {
      $flashModal = [
          'type' => session('domain_origin_detection_failed') ? 'warning' : 'error',
          'title' => session('domain_origin_detection_failed') ? 'Enter the server IP' : 'We could not complete this',
          'message' => session('domain_origin_detection_failed')
              ? 'We could not find your server automatically. Enter the server IP to continue setup.'
              : $safeSessionError,
          'icon' => session('domain_origin_detection_failed') ? 'shield-keyhole.svg' : 'triangle-exclamation.svg',
          'action_label' => session('domain_origin_detection_failed') ? 'Add server IP' : 'OK',
          'helper_text' => session('domain_origin_detection_failed')
              ? 'Your domain stays filled in. We will reopen the setup form.'
              : null,
          'action_event' => session('domain_origin_detection_failed') ? 'verifysky-open-server-ip' : null,
      ];
  } elseif ($errors->any()) {
      $flashModal = [
          'type' => 'error',
          'title' => 'Please check the form',
          'message' => implode("\n", $errors->all()),
          'icon' => 'triangle-exclamation.svg',
          'action_label' => 'OK',
          'helper_text' => null,
          'action_event' => null,
      ];
  }
@endphp

<body class="es-body h-screen overflow-hidden" x-data="{ isNavigating: false, navOpen: false }" x-on:beforeunload.window="isNavigating = true" x-on:pageshow.window="if ($event.persisted) isNavigating = false">

  <div x-show="isNavigating" x-transition.opacity.duration.250ms style="display: none; --borma-preloader-logo: url('{{ asset('borma-preloader-logo.png') }}');" class="borma-preloader">
    <div class="borma-preloader-stack">
      <div class="plane-animation track-preloader">
        <div class="circle"></div>
        <div class="circle"></div>
        <div class="circle"></div>
        <div class="circle"></div>
        <div class="circle"></div>
        <div class="circle"></div>
      </div>
      <div class="borma-preloader-progress" aria-hidden="true">
        <span></span>
      </div>
      <div class="borma-preloader-text">Loading...</div>
    </div>
  </div>

  @if($flashModal)
    <div
      x-data="{ open: true }"
      x-show="open"
      x-cloak
      x-on:keydown.escape.window="open = false"
      class="fixed inset-0 z-[9998] flex items-center justify-center px-4 py-6 sm:px-6"
      style="display: none;"
      aria-live="assertive"
      aria-modal="true"
      role="dialog"
    >
      <div x-show="open" x-transition.opacity.duration.180ms class="absolute inset-0 bg-[#0D1118]/72 backdrop-blur-[3px]"></div>

      <div
        x-show="open"
        x-transition:enter="transition ease-out duration-220"
        x-transition:enter-start="opacity-0 translate-y-3 sm:scale-95"
        x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
        x-transition:leave="transition ease-in duration-160"
        x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
        x-transition:leave-end="opacity-0 translate-y-2 sm:scale-95"
        class="es-flash-tulip w-full max-w-xl"
        x-bind:class="'es-flash-tulip-' + @js($flashModal['type'])"
      >
        <button type="button" x-on:click="open = false" class="es-flash-close" aria-label="Close message">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" class="h-4 w-4"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>

        <div class="es-flash-accent"></div>

        <div class="flex items-start gap-4">
          <span class="es-flash-icon-wrap">
            <img src="{{ asset('duotone/'.$flashModal['icon']) }}" alt="" class="es-duotone-icon h-5 w-5 {{ $flashModal['type'] === 'success' ? 'es-icon-tone-success' : ($flashModal['type'] === 'error' ? 'es-icon-tone-coral' : 'es-icon-tone-brass') }}">
          </span>

          <div class="min-w-0 flex-1">
            <div class="flex flex-wrap items-center gap-2">
              <span class="es-flash-pill">
                {{ $flashModal['type'] === 'success' ? 'Success' : ($flashModal['type'] === 'warning' ? 'Check this' : 'Error') }}
              </span>
              <span class="text-[10px] font-bold uppercase tracking-[0.18em] text-[#7F8BA0]">VerifySky</span>
            </div>

            <h3 class="mt-3 text-xl font-extrabold tracking-[-0.02em] text-[#FFFFFF]">{{ $flashModal['title'] }}</h3>

            <div class="mt-3 space-y-2 text-sm leading-7 text-[#D7E1F5]">
              @foreach(preg_split("/\\r\\n|\\r|\\n/", $flashModal['message']) as $line)
                @if(trim($line) !== '')
                  <p>{{ $line }}</p>
                @endif
              @endforeach
            </div>

            <div class="mt-5 flex flex-wrap items-center gap-3">
              <button
                type="button"
                x-on:click="
                  open = false;
                  @if(!empty($flashModal['action_event']))
                    window.dispatchEvent(new CustomEvent(@js($flashModal['action_event'])));
                  @endif
                "
                class="es-btn es-flash-btn"
                x-bind:class="'es-flash-btn-' + @js($flashModal['type'])"
              >
                <img src="{{ asset('duotone/circle-check.svg') }}" alt="" class="es-duotone-icon h-4 w-4" style="filter: brightness(0);">
                {{ $flashModal['action_label'] }}
              </button>
              @if(!empty($flashModal['helper_text']))
                <div class="text-xs text-[#AEB9CC]">{{ $flashModal['helper_text'] }}</div>
              @endif
            </div>
          </div>
        </div>
      </div>
    </div>
  @endif

  @php
  $navItems = [
      ['route' => 'dashboard', 'label' => 'Overview', 'desc' => 'Summary', 'icon' => 'eye-evil.svg'],
      ['route' => 'billing.index', 'label' => 'Billing', 'desc' => 'Subscription', 'icon' => 'sack-dollar.svg'],
      ['route' => 'domains.index', 'label' => 'Domains', 'desc' => 'Setup', 'icon' => 'spider-web.svg'],
      ['route' => 'firewall.index', 'label' => 'Firewall', 'desc' => 'Rules', 'icon' => 'shield-virus.svg'],
      ['route' => 'sensitive_paths.index', 'label' => 'Protected Paths', 'desc' => 'Important URLs', 'icon' => 'lock-keyhole.svg'],
      ['route' => 'logs.index', 'label' => 'Security Logs', 'desc' => 'Incidents', 'icon' => 'skull-crossbones.svg'],
      ['route' => 'ip_farm.index', 'label' => 'Blocked IPs', 'desc' => 'Block list', 'icon' => 'ban-bug.svg'],
    ];
    $sessionAvatarPath = session('user_avatar_path');
    $sessionAvatarPath = is_string($sessionAvatarPath) ? trim($sessionAvatarPath) : '';
    $sessionAvatarUrl = $sessionAvatarPath !== '' ? asset('storage/'.ltrim($sessionAvatarPath, '/')) : null;
  @endphp

  <div class="relative z-10 flex h-screen overflow-hidden">
    <div x-show="navOpen" x-on:click="navOpen = false" class="fixed inset-0 z-30 bg-black/70 backdrop-blur-sm md:hidden" style="display:none;"></div>

    <aside class="es-sidebar fixed inset-y-0 left-0 z-40 w-[min(19rem,calc(100vw-1rem))] -translate-x-full overflow-y-auto transition-transform duration-200 sm:w-64 md:translate-x-0" x-bind:class="navOpen ? 'translate-x-0' : '-translate-x-full md:translate-x-0'">
      <div class="flex h-full flex-col">
        <div class="es-brand-panel justify-between">
          <a href="{{ route('dashboard') }}" class="flex min-w-0 items-center gap-4">
            <div class="es-brand-mark">
              <img src="{{ asset('Logo.png') }}" alt="" class="es-brand-mark-logo">
            </div>
            <div class="min-w-0">
              <div class="truncate text-lg font-black uppercase leading-tight tracking-tight text-[#FCB900]">VerifySky</div>
              <div class="truncate text-xs font-medium text-[#D4C4AB]">Dashboard</div>
            </div>
          </a>
          <button class="es-icon-btn es-btn-secondary md:hidden" x-on:click="navOpen = false" type="button" aria-label="Close navigation">
            <img src="{{ asset('duotone/xmark.svg') }}" alt="" class="es-duotone-icon es-icon-tone-muted h-4 w-4">
          </button>
        </div>

        <nav class="flex-1 space-y-2 px-4 py-4">
          @foreach($navItems as $item)
            @php $active = request()->routeIs(str_replace('.index', '.*', $item['route'])) || request()->routeIs($item['route']); @endphp
            <a href="{{ route($item['route']) }}" class="es-nav-link {{ $active ? 'es-nav-link-active' : '' }}">
              <span class="es-nav-icon-wrap">
                <img src="{{ asset('duotone/'.$item['icon']) }}" alt="" class="es-duotone-icon {{ $active ? 'es-icon-tone-brass' : 'es-icon-tone-muted' }} h-4 w-4">
              </span>
              <span class="min-w-0">
                <span class="block truncate text-sm font-semibold">{{ $item['label'] }}</span>
              </span>
            </a>
          @endforeach
        </nav>

        <div class="px-4 pb-6">
          <a href="{{ route('domains.index') }}" class="es-sidebar-cta">
            <img src="{{ asset('duotone/plus.svg') }}" alt="" class="es-duotone-icon h-4 w-4" style="filter: brightness(0);">
            Add domain
          </a>
        </div>

        <div class="border-t border-white/8 px-4 py-4">
          <div class="rounded-lg border border-[#303540] bg-[#252A34] px-3 py-3">
            <div class="flex items-center gap-3">
              @if($sessionAvatarUrl)
                <img src="{{ $sessionAvatarUrl }}" alt="" class="h-10 w-10 shrink-0 rounded-full border border-[#303540] object-cover">
              @else
                <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-[#303540] bg-[#1B202A] text-xs font-black text-[#FCB900]">
                  {{ strtoupper(substr((string) session('user_name', session('admin_user', 'U')), 0, 1)) }}
                </div>
              @endif
              <div class="min-w-0">
                <div class="truncate text-sm font-bold text-[#FFFFFF]">{{ session('user_name', session('admin_user', 'User')) }}</div>
                <div class="mt-0.5 truncate text-[11px] text-[#AEB9CC]">{{ session('user_email', session('admin_user', '')) }}</div>
              </div>
            </div>
            <div class="mt-3 inline-flex rounded-md border border-[#303540] bg-[#1B202A] px-2 py-1 text-[10px] font-bold uppercase tracking-[0.16em] text-[#FCB900]">{{ ucfirst((string) session('user_role', session('is_admin') ? 'admin' : 'user')) }}</div>
          </div>
          <form method="POST" action="{{ route('logout') }}" class="mt-3">
            @csrf
            <button class="es-btn es-btn-secondary w-full" type="submit">Logout</button>
          </form>
        </div>
      </div>
    </aside>

    <div class="flex min-w-0 flex-1 flex-col md:pl-64">
      <header class="z-20 h-16 shrink-0 border-b border-[#303540]/70 bg-[#0E131D]/95 backdrop-blur">
        <div class="flex h-full items-center justify-between gap-3 px-4 py-3 sm:px-6">
          <div class="flex min-w-0 flex-1 items-center gap-3">
            <button class="es-icon-btn es-btn-secondary md:hidden" x-on:click="navOpen = true" type="button" aria-label="Open navigation">
              <img src="{{ asset('duotone/bars.svg') }}" alt="" class="es-duotone-icon es-icon-tone-muted h-4 w-4">
            </button>
            <div class="vs-owl-stage" aria-hidden="true">
              <div class="vs-owl-skyline"></div>
              <div class="vs-owl-sentinel">
                <div class="vs-owl-glow"></div>
                <svg class="vs-owl-mark" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 384 384" fill="currentColor">
                  <g>
                    <path class="vs-owl-crown" d="M187.65 122.03c1.95 1.95 5.14 1.95 7.1 0l37.3-37.3c1.95-1.95 1.95-5.14 0-7.1l-37.3-37.3c-1.95-1.95-5.14-1.95-7.1 0l-37.3 37.3c-1.95 1.95-1.95 5.14 0 7.1l37.3 37.3z"/>
                    <path class="vs-owl-wing vs-owl-wing-right" d="M337.04 159.25c-10.22 1.48-19.64 4.8-28.3 10.56-3.73 2.5-4.88 3.98-1.73 8.47 9.26 13.2 7.43 30.32-3.5 41.14-10.91 10.81-27.83 12.39-41.13 3.3-3.8-2.62-5.54-2.17-8.4 0.83-6.4 6.83-12.91 13.63-19.89 19.84-4.33 3.84-3.37 6.06 0.58 9.02 23.51 17.91 49.07 22.17 76.18 9.96 27.81-12.57 41.01-35.36 42.51-65.35-0.13-11.7-2.83-22.43-7.57-32.66-1.9-4.1-4.25-5.55-8.65-4.96z"/>
                    <path class="vs-owl-wing vs-owl-wing-left" d="M148.38 243.24c-7-6.21-13.5-13.01-19.91-19.83-2.86-3-4.64-3.45-8.42-0.83-13.3 9.14-30.23 7.49-41.15-3.3-10.93-10.82-12.76-27.94-3.52-41.14 3.14-4.49 2-5.96-1.72-8.46-8.65-5.76-18.09-9.08-28.33-10.57-4.41-0.58-6.77 0.87-8.66 4.96-4.73 10.22-7.43 20.96-7.56 32.66 1.5 29.99 14.69 52.78 42.5 65.35 27.11 12.21 52.67 7.95 76.18-9.96 3.96-2.96 4.91-5.18 0.6-9.01z"/>
                    <path class="vs-owl-core" d="M208.87 260c-4.73-3.68-4.07-5.72-.16-9.59 28.4-28.07 133.35-133 158.24-157.8-26.67-17.18-61.18-14.47-86.98 13.39-27.1 29.29-56.28 56.67-84.33 85.12-1.71 1.73-3.1 2.83-4.45 3.24-1.34-.41-2.73-1.51-4.44-3.24-28.05-28.45-57.23-55.83-84.33-85.12-25.8-27.86-60.31-30.57-86.98-13.39 24.89 24.8 129.85 129.73 158.24 157.8 3.91 3.87 4.57 5.91-.16 9.59-5.63 4.37-14.7 8.03-15.17 14.26-.42 5.55 19.73 35.23 27.81 47.79h.02v-.01h.01v.01c8.06-12.55 28.2-42.24 27.79-47.78-.45-6.24-9.51-9.89-15.15-14.27z"/>
                    <circle class="vs-owl-eye vs-owl-eye-left" cx="164" cy="174" r="9"/>
                    <circle class="vs-owl-eye vs-owl-eye-right" cx="220" cy="174" r="9"/>
                    <path class="vs-owl-beak" d="M192 194l-20 24h40l-20-24z"/>
                  </g>
                </svg>
              </div>
              <div class="vs-threat-packet">
                <span class="vs-threat-dot"></span>
                <span class="vs-threat-ip">203.0.113.7</span>
              </div>
              <div class="vs-capture-flash"></div>
            </div>
          </div>
          <div class="flex min-w-0 items-center gap-2 sm:gap-3">
            <button class="inline-flex h-10 w-10 items-center justify-center rounded-lg border border-[#303540] bg-[#1B202A] text-[#AEB9CC] transition-colors hover:border-[#FCB900]/50 hover:text-[#FCB900]" type="button" aria-label="Notifications"><img src="{{ asset('duotone/bell.svg') }}" alt="" class="es-duotone-icon es-icon-tone-muted h-4 w-4"></button>
            <a class="hidden h-10 items-center gap-2 rounded-lg border border-[#303540] bg-[#1B202A] px-3 text-sm font-semibold text-[#D7E1F5] transition-colors hover:border-[#FCB900]/50 hover:text-[#FCB900] sm:inline-flex" href="{{ route('settings.index') }}" aria-label="Settings">
              <img src="{{ asset('duotone/gear.svg') }}" alt="" class="es-duotone-icon es-icon-tone-muted h-4 w-4">
              Settings
            </a>
            <a class="inline-flex h-10 w-10 items-center justify-center rounded-lg border border-[#303540] bg-[#1B202A] text-[#AEB9CC] transition-colors hover:border-[#FCB900]/50 hover:text-[#FCB900] sm:hidden" href="{{ route('settings.index') }}" aria-label="Settings"><img src="{{ asset('duotone/gear.svg') }}" alt="" class="es-duotone-icon es-icon-tone-muted h-4 w-4"></a>
            <div class="vs-motivation-pill hidden md:inline-flex">
              <span class="vs-motivation-spark"></span>
              <span class="whitespace-nowrap">togther we can do it better</span>
            </div>
            <div class="flex h-10 w-10 shrink-0 items-center justify-center overflow-hidden rounded-full border border-[#303540] bg-[#252A34] text-xs font-bold text-[#FCB900]">
              @if($sessionAvatarUrl)
                <img src="{{ $sessionAvatarUrl }}" alt="" class="h-full w-full object-cover">
              @else
                {{ strtoupper(substr((string) session('user_name', session('admin_user', 'U')), 0, 1)) }}
              @endif
            </div>
          </div>
        </div>
      </header>

      <main class="min-h-0 flex-1 overflow-y-auto px-4 py-6 sm:px-6 md:px-8 md:py-8">
        <div class="mx-auto w-full max-w-none">
          @if(!empty($layoutBillingStatus['is_pass_through']))
            <div class="es-billing-banner">
              <div class="es-billing-banner-shell">
                <div class="es-billing-banner-copy">
                  <span class="es-billing-banner-pill">Protection Disabled</span>
                  <div>
                    <div class="es-billing-banner-title">Your current VerifySky quota has been exhausted.</div>
                    <p class="es-billing-banner-text">All of your domains are currently running without VerifySky protection. Upgrade or reset your cycle to restore active enforcement.</p>
                  </div>
                </div>
                <div class="es-billing-banner-meta">
                  <div class="es-billing-banner-meta-label">Current Cycle</div>
                  <div class="es-billing-banner-meta-value">{{ $layoutBillingStatus['current_cycle_start_at']->format('Y-m-d') }} to {{ $layoutBillingStatus['current_cycle_end_at']->format('Y-m-d') }}</div>
                </div>
              </div>
            </div>
          @endif

          @yield('content')
        </div>
      </main>
    </div>
  </div>
</body>
</html>
