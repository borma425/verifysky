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

<body class="es-body" x-data="{ isNavigating: false, navOpen: false }" x-on:beforeunload.window="isNavigating = true" x-on:pageshow.window="if ($event.persisted) isNavigating = false">

  <div x-show="isNavigating" x-transition.opacity.duration.250ms style="display: none;" class="fixed inset-0 z-[9999] flex items-center justify-center bg-[#171C26]/88 backdrop-blur-md">
    <div class="flex flex-col items-center gap-4">
      <img src="{{ asset('Logo.png') }}" alt="VerifySky" class="es-loader-logo animate-pulse">
      <div class="text-[11px] font-bold uppercase tracking-[0.24em] text-[#D7E1F5]">Syncing Control Plane...</div>
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
      ['route' => 'dashboard', 'label' => 'Overview', 'desc' => 'Telemetry', 'icon' => 'grid-horizontal.svg'],
      ['route' => 'billing.index', 'label' => 'Billing', 'desc' => 'Subscription', 'icon' => 'circle-check.svg'],
      ['route' => 'domains.index', 'label' => 'Domains', 'desc' => 'Onboarding', 'icon' => 'network-wired.svg'],
      ['route' => 'firewall.index', 'label' => 'Global Firewall', 'desc' => 'Policy Layer', 'icon' => 'shield-keyhole.svg'],
      ['route' => 'sensitive_paths.index', 'label' => 'Sensitive Paths', 'desc' => 'Hard Locks', 'icon' => 'lock-keyhole.svg'],
      ['route' => 'logs.index', 'label' => 'Security Logs', 'desc' => 'Incidents', 'icon' => 'clipboard.svg'],
      ['route' => 'ip_farm.index', 'label' => 'IP Farm', 'desc' => 'Network Feed', 'icon' => 'signal-good.svg'],
    ];
  @endphp

  <div class="relative z-10 flex min-h-screen">
    <div x-show="navOpen" x-on:click="navOpen = false" class="fixed inset-0 z-30 bg-black/70 backdrop-blur-sm lg:hidden" style="display:none;"></div>

    <aside class="es-sidebar fixed inset-y-0 left-0 z-40 w-80 -translate-x-full transition-transform duration-200 lg:translate-x-0" x-bind:class="navOpen ? 'translate-x-0' : '-translate-x-full lg:translate-x-0'">
      <div class="flex h-full flex-col">
        <a href="{{ route('dashboard') }}" class="es-brand-panel">
          <img src="{{ asset('Logo.png') }}" alt="VerifySky" class="es-brand-logo">
          <div class="sr-only">
            <div>VerifySky</div>
            <div>VerifySky Control Plane</div>
          </div>
        </a>

        <nav class="flex-1 space-y-1 px-3 py-4">
          @foreach($navItems as $item)
            @php $active = request()->routeIs(str_replace('.index', '.*', $item['route'])) || request()->routeIs($item['route']); @endphp
            <a href="{{ route($item['route']) }}" class="es-nav-link {{ $active ? 'es-nav-link-active' : '' }}">
              <span class="es-nav-icon-wrap">
                <img src="{{ asset('duotone/'.$item['icon']) }}" alt="{{ $item['label'] }}" class="es-duotone-icon {{ $active ? 'es-icon-tone-brass' : 'es-icon-tone-muted' }} h-4 w-4">
              </span>
              <span class="min-w-0">
                <span class="block truncate text-sm font-semibold">{{ $item['label'] }}</span>
                <span class="block truncate text-[10px] uppercase tracking-[0.18em] text-[#76859C]">{{ $item['desc'] }}</span>
              </span>
            </a>
          @endforeach
        </nav>

        <div class="border-t border-white/8 px-4 py-4">
          <div class="rounded-lg border border-white/10 bg-[#313A4B] px-3 py-2.5 text-[11px]">
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

    <div class="min-w-0 flex-1 lg:pl-80">
      <header class="es-topbar sticky top-0 z-20">
        <div class="flex items-center justify-between px-4 py-3 sm:px-6">
          <div class="flex items-center gap-3">
            <button class="es-icon-btn es-btn-secondary lg:hidden" x-on:click="navOpen = true" type="button" aria-label="Open navigation">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" class="h-4 w-4"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
            </button>
            <div class="hidden sm:block">
              <div class="text-[10px] uppercase tracking-[0.24em] text-[#76859C]">Command Layer</div>
              <div class="text-sm font-semibold text-[#FFFFFF]">Traffic Intelligence, Policy and Edge Enforcement</div>
            </div>
          </div>
          <div class="rounded-full border border-white/10 bg-[#313A4B] px-3 py-1.5 text-[11px] text-[#D7E1F5]">
            <span class="inline-block h-1.5 w-1.5 rounded-full bg-[#FCB900]"></span>
            <span class="ml-1.5 uppercase tracking-[0.15em]">Edge Mesh Healthy</span>
          </div>
        </div>
      </header>

      <main class="px-4 py-6 sm:px-6 lg:py-8">
        <div class="mx-auto w-full max-w-[1280px]">
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
