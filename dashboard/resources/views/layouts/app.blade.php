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
  <title>{{ $title ?? 'VerifySky Control Plane' }}</title>
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
          'title' => 'Operation Completed',
          'message' => session('status'),
          'icon' => 'circle-check.svg',
          'action_label' => 'Understood',
          'helper_text' => 'This message came from the current request result.',
          'action_event' => null,
      ];
  } elseif ($safeSessionError !== null) {
      $flashModal = [
          'type' => session('domain_origin_detection_failed') ? 'warning' : 'error',
          'title' => session('domain_origin_detection_failed') ? 'Enter The Server IP' : 'Action Could Not Complete',
          'message' => session('domain_origin_detection_failed')
              ? 'We could not detect the real server automatically because this domain already sits behind an edge or DNS proxy. Enter the real server IP to continue setup.'
              : $safeSessionError,
          'icon' => session('domain_origin_detection_failed') ? 'shield-keyhole.svg' : 'triangle-exclamation.svg',
          'action_label' => session('domain_origin_detection_failed') ? 'Add Server IP' : 'Understood',
          'helper_text' => session('domain_origin_detection_failed')
              ? 'Your domain stays filled in. We will reopen the same step and show the server IP field automatically.'
              : 'This message came from the current request result.',
          'action_event' => session('domain_origin_detection_failed') ? 'verifysky-open-server-ip' : null,
      ];
  } elseif ($errors->any()) {
      $flashModal = [
          'type' => 'error',
          'title' => 'Please Review The Form',
          'message' => implode("\n", $errors->all()),
          'icon' => 'triangle-exclamation.svg',
          'action_label' => 'Understood',
          'helper_text' => 'This message came from the current request result.',
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
        <button type="button" x-on:click="open = false" class="es-flash-close" aria-label="Close notification">
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
                {{ $flashModal['type'] === 'success' ? 'Success' : ($flashModal['type'] === 'warning' ? 'Needs Attention' : 'Error') }}
              </span>
              <span class="text-[10px] font-bold uppercase tracking-[0.18em] text-[#7F8BA0]">VerifySky Notification</span>
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
              <div class="text-xs text-[#AEB9CC]">{{ $flashModal['helper_text'] }}</div>
            </div>
          </div>
        </div>
      </div>
    </div>
  @endif

  @php
    $navItems = [
      ['route' => 'dashboard', 'label' => 'Overview', 'desc' => 'Telemetry', 'icon' => 'eye-evil.svg'],
      ['route' => 'billing.index', 'label' => 'Billing', 'desc' => 'Subscription', 'icon' => 'sack-dollar.svg'],
      ['route' => 'domains.index', 'label' => 'Domains', 'desc' => 'Onboarding', 'icon' => 'spider-web.svg'],
      ['route' => 'firewall.index', 'label' => 'Global Firewall', 'desc' => 'Policy Layer', 'icon' => 'shield-virus.svg'],
      ['route' => 'sensitive_paths.index', 'label' => 'Sensitive Paths', 'desc' => 'Hard Locks', 'icon' => 'lock-keyhole.svg'],
      ['route' => 'logs.index', 'label' => 'Security Logs', 'desc' => 'Incidents', 'icon' => 'skull-crossbones.svg'],
      ['route' => 'ip_farm.index', 'label' => 'IP Farm', 'desc' => 'Network Feed', 'icon' => 'ban-bug.svg'],
    ];
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
              <div class="truncate text-xs font-medium text-[#D4C4AB]">Control Plane</div>
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
            Add New Domain
          </a>
        </div>

        <div class="border-t border-white/8 px-4 py-4">
          <div class="rounded-lg border border-white/10 bg-[#252A34] px-3 py-2.5 text-[11px]">
            <div class="flex items-center gap-2">
              <img src="{{ asset('duotone/shield-check.svg') }}" alt="role" class="es-duotone-icon es-icon-tone-brass h-4 w-4">
              <span class="font-semibold text-[#FFFFFF]">{{ session('user_name', session('admin_user', 'User')) }}</span>
            </div>
            <div class="mt-1 uppercase tracking-[0.18em] text-[10px] text-[#76859C]">{{ ucfirst((string) session('user_role', session('is_admin') ? 'admin' : 'user')) }}</div>
          </div>
          <form method="POST" action="{{ route('logout') }}" class="mt-3">
            @csrf
            <button class="es-btn es-btn-secondary w-full" type="submit">Logout</button>
          </form>
        </div>
      </div>
    </aside>

    <div class="flex min-w-0 flex-1 flex-col md:pl-64">
      <header class="es-topbar z-20 h-16 shrink-0">
        <div class="flex h-full items-center justify-between px-4 py-3 sm:px-6">
          <div class="flex items-center gap-3">
            <button class="es-icon-btn es-btn-secondary md:hidden" x-on:click="navOpen = true" type="button" aria-label="Open navigation">
              <img src="{{ asset('duotone/bars.svg') }}" alt="" class="es-duotone-icon es-icon-tone-muted h-4 w-4">
            </button>
          </div>
          <div class="flex items-center gap-4">
            <div class="relative hidden items-center rounded-full border border-[#504532]/20 bg-[#303540] px-4 py-1.5 transition-colors focus-within:border-[#FCB900]/50 md:flex">
              <input class="h-6 w-48 border-none bg-transparent p-0 font-mono text-sm text-[#DEE2F0] placeholder:text-[#D4C4AB] focus:ring-0" placeholder="Search control plane..." type="text">
              <img src="{{ asset('duotone/magnifying-glass.svg') }}" alt="" class="es-duotone-icon es-icon-tone-muted h-3.5 w-3.5">
            </div>
            <button class="es-top-icon" type="button" aria-label="Notifications"><img src="{{ asset('duotone/bell.svg') }}" alt="" class="es-duotone-icon es-icon-tone-muted h-4 w-4"></button>
            <a class="es-top-icon" href="{{ route('settings.index') }}" aria-label="Settings"><img src="{{ asset('duotone/gear.svg') }}" alt="" class="es-duotone-icon es-icon-tone-muted h-4 w-4"></a>
            <div class="hidden rounded-full border border-white/10 bg-[#303540] px-3 py-1.5 text-[11px] text-[#D7E1F5] sm:block">
              <span class="inline-block h-1.5 w-1.5 rounded-full bg-[#FCB900]"></span>
              <span class="ml-1.5 uppercase tracking-[0.15em]">Edge Mesh Healthy</span>
            </div>
            <div class="flex h-8 w-8 items-center justify-center overflow-hidden rounded-full border border-[#504532]/20 bg-[#343944] text-xs font-bold text-[#FCB900]">
              {{ strtoupper(substr((string) session('user_name', session('admin_user', 'U')), 0, 1)) }}
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
