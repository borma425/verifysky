@extends('layouts.customer-mirror')

@section('content')
  @php
    $planLabel = ucfirst(str_replace('_', ' ', (string) $plan_key));
    $domainsUsageLabel = $domains_limit === null ? 'Unlimited' : $domains_used.' / '.$domains_limit.' domains';
  @endphp

  <section class="es-page-header">
    <div class="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
      <div>
        <p class="text-[10px] font-bold uppercase tracking-[0.24em] text-[#FCB900]">Customer Domains</p>
        <h1 class="es-page-title mt-1 font-black tracking-[-0.03em] text-[#FFFFFF]">Domain Management</h1>
        <p class="mt-2 max-w-2xl text-sm leading-relaxed text-[#D7E1F5]">Rendered from the selected user without session impersonation.</p>
      </div>
      <div class="es-header-pill min-w-[7rem] text-center">
        <div class="text-[10px] uppercase tracking-[0.2em] text-[#959BA7]">Plan</div>
        <div class="mt-1 text-sm font-semibold leading-none text-[#FFFFFF]">{{ $planLabel }}</div>
        <div class="mt-1 text-[11px] text-[#D7E1F5]">{{ $domainsUsageLabel }}</div>
      </div>
    </div>
  </section>

  <div class="space-y-4">
    @forelse($preparedDomainGroups as $group)
      <div class="es-card p-5">
        <div class="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
          <div>
            <div class="text-lg font-bold text-white">{{ $group['display_domain'] }}</div>
            <div class="mt-1 text-sm text-sky-100/70">{{ ucfirst((string) $group['overall_status']) }} / {{ ucfirst((string) $group['mode']) }}</div>
          </div>
          <a href="{{ route('admin.tenants.customer.domains.tuning', [$tenant, $group['primary_domain']]) }}" class="es-btn es-btn-secondary">Open settings</a>
        </div>
        <div class="mt-4 grid gap-3 md:grid-cols-3">
          @foreach($group['health_rows'] as $row)
            <div class="rounded-xl border border-white/10 bg-[#202632] p-4">
              <div class="font-semibold text-white">{{ $row['domain_name'] }}</div>
              <div class="mt-2 text-xs text-sky-100/70">DNS: {{ $row['hostname_status_normalized'] }}</div>
              <div class="mt-1 text-xs text-sky-100/70">SSL: {{ $row['ssl_status_label'] }}</div>
            </div>
          @endforeach
        </div>
      </div>
    @empty
      <div class="es-card p-5 text-sm text-sky-100/70">No protected domains are assigned to this client yet.</div>
    @endforelse
  </div>
@endsection
