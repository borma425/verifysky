<!doctype html>
<html lang="en" class="h-full">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex, nofollow, noarchive, nosnippet, noimageindex">
  <link rel="icon" type="image/x-icon" href="{{ asset('favicon.ico') }}">
  <title>{{ $title ?? 'VerifySky Customer Mirror' }}</title>
  @vite(['resources/css/app.css', 'resources/js/app.js'])
  <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
</head>
@php
  $navItems = [
      ['route' => 'admin.tenants.customer.overview', 'label' => 'Overview', 'desc' => 'Summary', 'icon' => 'grid-horizontal.svg'],
      ['route' => 'admin.tenants.customer.billing.index', 'label' => 'Billing', 'desc' => 'Subscription', 'icon' => 'circle-check.svg'],
      ['route' => 'admin.tenants.customer.domains.index', 'label' => 'Domains', 'desc' => 'Setup', 'icon' => 'network-wired.svg'],
      ['route' => 'admin.tenants.customer.firewall.index', 'label' => 'Firewall', 'desc' => 'Rules', 'icon' => 'shield-keyhole.svg'],
      ['route' => 'admin.tenants.customer.logs.index', 'label' => 'Security Logs', 'desc' => 'Incidents', 'icon' => 'clipboard.svg'],
  ];
@endphp
<body class="es-body" x-data="{ navOpen: false }">
  <div class="relative z-10 flex min-h-screen">
    <div x-show="navOpen" x-on:click="navOpen = false" class="fixed inset-0 z-30 bg-black/70 backdrop-blur-sm lg:hidden" style="display:none;"></div>

    <aside class="es-sidebar fixed inset-y-0 left-0 z-40 w-80 -translate-x-full transition-transform duration-200 lg:translate-x-0" x-bind:class="navOpen ? 'translate-x-0' : '-translate-x-full lg:translate-x-0'">
      <div class="flex h-full flex-col">
        <a href="{{ route('admin.tenants.customer.overview', $tenant) }}" class="es-brand-panel">
          <img src="{{ asset('Logo.png') }}" alt="VerifySky" class="es-brand-logo">
          <div class="sr-only">VerifySky Customer Mirror</div>
        </a>

        <nav class="flex-1 space-y-1 px-3 py-4">
          @foreach($navItems as $item)
            @php $active = request()->routeIs($item['route']); @endphp
            <a href="{{ route($item['route'], $tenant) }}" class="es-nav-link {{ $active ? 'es-nav-link-active' : '' }}">
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
            <div class="font-semibold text-[#FFFFFF]">{{ $tenant->name }}</div>
            <div class="mt-1 uppercase tracking-[0.18em] text-[10px] text-[#76859C]">Read-only Mirror</div>
          </div>
          <a href="{{ route('admin.tenants.show', $tenant) }}" class="es-btn es-btn-secondary mt-3 w-full text-center">Back To Admin</a>
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
            <div>
              <div class="text-[10px] uppercase tracking-[0.24em] text-[#76859C]">Admin Mirror</div>
              <div class="text-sm font-semibold text-[#FFFFFF]">{{ $mirrorPageTitle ?? 'Viewing customer interface' }}</div>
            </div>
          </div>
          <div class="rounded-full border border-[#FCB900]/20 bg-[#FCB900]/10 px-3 py-1.5 text-[11px] font-bold uppercase tracking-[0.15em] text-[#FFE6B5]">
            Read Only
          </div>
        </div>
      </header>

      <main class="px-4 py-6 sm:px-6 lg:py-8">
        <div class="mx-auto w-full max-w-[1280px]">
          <div class="mb-6 rounded-2xl border border-[#FCB900]/20 bg-[#FCB900]/10 px-4 py-3 text-sm text-[#FFE6B5]">
            <span class="font-bold text-[#FFFFFF]">Viewing as customer: {{ $tenant->name }}</span>
            <span class="ml-2">This view is read-only.</span>
          </div>

          @yield('content')
        </div>
      </main>
    </div>
  </div>
</body>
</html>
