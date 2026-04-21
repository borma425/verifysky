@extends('layouts.app')

@section('content')
  @php
    $totalDomains = count($preparedDomainGroups);
    $planLabel = ucfirst(str_replace('_', ' ', (string) $plan_key));
    $domainsUsageLabel = $domains_limit === null ? 'Unlimited' : $domains_used.' / '.$domains_limit.' domains';
  @endphp

  <div
    class="space-y-6"
    x-data="domainIndex({ openWizard: @js($can_add_domain && old('domain_name') !== null), selectedDomain: 0 })"
    x-on:verifysky-open-server-ip.window="openWizard(); $nextTick(() => window.dispatchEvent(new CustomEvent('verifysky-focus-server-ip')))"
  >
    <section class="es-page-header">
      <div class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_auto] xl:items-end">
        <div class="flex items-start gap-4">
          <span class="es-nav-icon-wrap mt-1">
            <img src="{{ asset('duotone/network-wired.svg') }}" alt="Domain operations" class="es-duotone-icon es-icon-tone-brass h-5 w-5">
          </span>
          <div class="min-w-0">
            <p class="text-[10px] font-bold uppercase tracking-[0.24em] text-[#FCB900]">Domains Command Center</p>
            <h2 class="es-page-title mt-1 font-black tracking-[-0.03em] text-[#FFFFFF]">Domain Management</h2>
            <p class="mt-2 max-w-2xl text-sm leading-relaxed text-[#D7E1F5]">Operate DNS onboarding, certificate readiness, and runtime protection from one calm command surface.</p>
          </div>
        </div>

        <div class="flex flex-wrap items-center gap-3 xl:justify-end">
          <div class="es-header-pill min-w-[7rem] text-center">
            <div class="text-[10px] uppercase tracking-[0.2em] text-[#959BA7]">Plan</div>
            <div class="mt-1 text-sm font-semibold leading-none text-[#FFFFFF]">{{ $planLabel }}</div>
            <div class="mt-1 text-[11px] text-[#D7E1F5]">{{ $domainsUsageLabel }}</div>
          </div>
          <div class="es-header-pill min-w-[5.5rem] text-center">
            <div class="text-[10px] uppercase tracking-[0.2em] text-[#959BA7]">Domains</div>
            <div class="mt-1 font-mono text-lg font-semibold leading-none text-[#FFFFFF]">{{ $totalDomains }}</div>
          </div>
          @if(! $can_add_domain)
            <div class="rounded-xl border border-[#FCB900]/24 bg-[#FCB900]/10 px-4 py-2 text-sm text-[#FFFFFF]">
              <div class="text-[10px] font-bold uppercase tracking-[0.18em] text-[#FCB900]">Upgrade Required</div>
              <div class="mt-1">Upgrade your plan to add more domains.</div>
            </div>
          @endif
          <button
            @if($can_add_domain) x-on:click="openWizard" @endif
            class="es-btn es-header-cta flex items-center gap-2 px-4 py-2 disabled:cursor-not-allowed disabled:opacity-60"
            @disabled(! $can_add_domain)
          >
            <img src="{{ asset('duotone/panel-ews.svg') }}" alt="Add domain" class="h-4 w-4 opacity-75" style="filter: brightness(0);">
            Add New Domain
          </button>
        </div>
      </div>
    </section>

    @include('domains.partials.index.alerts')
    @include('domains.partials.index.create-domain-modal')

    @include('domains.partials.index.domains-list')
  </div>
@endsection
