@php
  $navItems = [
      ['route' => 'admin.overview', 'label' => 'Overview', 'icon' => 'grid-horizontal.svg'],
      ['route' => 'admin.tenants.index', 'label' => 'Tenants', 'icon' => 'clipboard.svg'],
      ['route' => 'admin.settings.index', 'label' => 'Settings', 'icon' => 'sliders.svg'],
      ['route' => 'admin.logs.security', 'label' => 'System Logs', 'icon' => 'shield-keyhole.svg'],
  ];
@endphp

<aside class="es-sidebar fixed inset-y-0 left-0 z-40 w-80 -translate-x-full transition-transform duration-200 lg:translate-x-0" x-bind:class="navOpen ? 'translate-x-0' : '-translate-x-full lg:translate-x-0'">
  <div class="flex h-full flex-col">
    <a href="{{ route('admin.overview') }}" class="es-brand-panel">
      <img src="{{ asset('Logo.png') }}" alt="VerifySky" class="es-brand-logo">
      <div class="sr-only">VerifySky Admin Command Center</div>
    </a>

    <nav class="flex-1 space-y-1 px-3 py-4">
      @foreach($navItems as $item)
        @php
          $active = request()->routeIs($item['route'])
              || ($item['route'] === 'admin.tenants.index' && request()->routeIs('admin.tenants.*'))
              || ($item['route'] === 'admin.logs.security' && request()->routeIs('admin.logs.*'));
        @endphp
        <a href="{{ route($item['route']) }}" class="es-nav-link {{ $active ? 'es-nav-link-active' : '' }}">
          <span class="es-nav-icon-wrap">
            <img src="{{ asset('duotone/'.$item['icon']) }}" alt="" class="es-duotone-icon {{ $active ? 'es-icon-tone-brass' : 'es-icon-tone-muted' }} h-4 w-4">
          </span>
          <span class="min-w-0">
            <span class="block truncate text-sm font-semibold">{{ $item['label'] }}</span>
            <span class="block truncate text-[11px] uppercase tracking-[0.16em] text-[#7F8BA0]">Admin Only</span>
          </span>
        </a>
      @endforeach
    </nav>

    <div class="border-t border-white/10 p-4">
      <form method="POST" action="{{ route('logout') }}">
        @csrf
        <button type="submit" class="text-xs font-semibold uppercase tracking-[0.16em] text-[#7F8BA0] hover:text-white">Logout</button>
      </form>
    </div>
  </div>
</aside>
