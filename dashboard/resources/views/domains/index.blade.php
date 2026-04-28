@extends('layouts.app')

@section('content')
  @php
    $totalDomains = count($preparedDomainGroups);
    $planLabel = ucfirst(str_replace('_', ' ', (string) $plan_key));
    $domainsUsageLabel = $domains_limit === null ? 'Unlimited' : $domains_used.' / '.$domains_limit.' domains';
    $targetDomain = $preparedDomainGroups[0]['primary_domain'] ?? 'No domain selected';
  @endphp

  <div
    class="flex min-h-[calc(100vh-8rem)] flex-col gap-6"
    x-data="domainIndex({ openWizard: @js($can_add_domain && old('domain_name') !== null), selectedDomain: 0, statusUrl: @js(route('domains.statuses')), polling: @js($domains_needs_polling) })"
    x-on:verifysky-open-server-ip.window="openWizard(); $nextTick(() => window.dispatchEvent(new CustomEvent('verifysky-focus-server-ip')))"
  >
    <section class="es-page-header">
      <div class="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
        <div class="min-w-0">
          <p class="mb-1 text-xs font-semibold uppercase tracking-[0.1em] text-[#D4C4AB]">Domains Command Center</p>
          <div class="flex flex-wrap items-center gap-4">
            <h2 class="text-3xl font-bold tracking-wide text-[#DEE2F0]">Domain Management</h2>
            <div class="hidden items-center gap-2 md:flex">
              <span class="rounded-full border border-[#504532]/15 bg-[#303540] px-3 py-1 font-mono text-xs text-[#BDC7DB]">Target: {{ $targetDomain }}</span>
              <span class="rounded-full border border-[#504532]/15 bg-[#303540] px-2 py-1 font-mono text-xs text-[#D4C4AB]">Total: {{ $totalDomains }}</span>
              <span class="rounded-full border border-[#504532]/15 bg-[#303540] px-2 py-1 text-xs text-[#D4C4AB]">{{ $planLabel }} · {{ $domainsUsageLabel }}</span>
            </div>
          </div>
          @if(! $can_add_domain)
            <div class="mt-3 max-w-xl rounded-lg border border-[#FCB900]/24 bg-[#FCB900]/10 px-4 py-2 text-sm text-[#FFFFFF]">
              Upgrade your plan to add more domains.
            </div>
          @endif
        </div>

        <div class="flex flex-wrap items-center gap-3 md:justify-end">
          <button
            @if($can_add_domain) x-on:click="openWizard" @endif
            class="es-btn flex shrink-0 items-center gap-2 px-5 py-2.5 disabled:cursor-not-allowed disabled:opacity-60"
            @disabled(! $can_add_domain)
          >
            <img src="{{ asset('duotone/panel-ews.svg') }}" alt="" class="h-4 w-4 opacity-75" style="filter: brightness(0);">
            Add New Domain
          </button>
        </div>
      </div>
    </section>

    <div class="flex flex-col gap-4 md:hidden">
      <div class="flex flex-wrap gap-2">
        <span class="rounded-full border border-[#504532]/15 bg-[#303540] px-3 py-1 font-mono text-xs text-[#BDC7DB]">Target: {{ $targetDomain }}</span>
        <span class="rounded-full border border-[#504532]/15 bg-[#303540] px-2 py-1 font-mono text-xs text-[#D4C4AB]">Total: {{ $totalDomains }}</span>
      </div>
    </div>

    @include('domains.partials.index.alerts')
    @include('domains.partials.index.create-domain-modal')

    @include('domains.partials.index.domains-list')
  </div>
@endsection
