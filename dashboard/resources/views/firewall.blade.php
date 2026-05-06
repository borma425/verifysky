@extends('layouts.app')

@section('content')
  @php
    $firewallUsed = (int) ($firewallUsage['used'] ?? 0);
    $firewallLimit = $firewallUsage['limit'] ?? null;
    $firewallUsagePercent = $firewallLimit ? min(100, (int) round(($firewallUsed / max(1, (int) $firewallLimit)) * 100)) : 100;
  @endphp

  <section class="vs-fw-page es-animate">
    <div class="vs-fw-command">
      <div>
        <p class="vs-fw-eyebrow">Firewall</p>
        <h1 class="vs-fw-title">Firewall Rules</h1>
        <p class="vs-fw-subtitle">Choose what traffic to allow, challenge, or block.</p>
      </div>

      @if(!empty($firewallUsage))
        <div class="vs-fw-plan">
          <span class="vs-fw-plan-mark">
            <img src="{{ asset('duotone/shield-virus.svg') }}" alt="" class="es-duotone-icon es-icon-tone-coral h-5 w-5">
          </span>
          <div class="min-w-0">
            <div class="vs-fw-plan-name">{{ $firewallUsage['plan_name'] ?? 'Plan' }}</div>
            <div class="vs-fw-plan-bar"><span style="width: {{ $firewallUsagePercent }}%"></span></div>
          </div>
          <div class="vs-fw-plan-count">
            @if(($firewallUsage['limit'] ?? null) !== null)
              {{ $firewallUsage['used'] ?? 0 }} / {{ $firewallUsage['limit'] ?? 0 }} custom rules
            @else
              Unlimited custom rules
            @endif
          </div>
        </div>
      @endif
    </div>

    <div class="vs-fw-stat-grid">
      <div class="vs-fw-stat">
        <span class="vs-fw-stat-icon">Σ</span>
        <span>
          <span class="vs-fw-stat-label">Total Rules</span>
          <strong>{{ number_format($totalRules ?? count($firewallRules ?? [])) }}</strong>
        </span>
      </div>
      <div class="vs-fw-stat">
        <span class="vs-fw-stat-icon">AI</span>
        <span>
          <span class="vs-fw-stat-label">Auto Rules</span>
          <strong>{{ number_format(count($aiRules ?? [])) }}</strong>
        </span>
      </div>
      <div class="vs-fw-stat">
        <span class="vs-fw-stat-icon">M</span>
        <span>
          <span class="vs-fw-stat-label">Manual Rules</span>
          <strong>{{ number_format(count($manualRules ?? [])) }}</strong>
        </span>
      </div>
    </div>

    @if(!empty($loadErrors))
      <div class="vs-fw-alert-stack">
      @foreach($loadErrors as $msg)
        <div class="vs-fw-alert">
          <img src="{{ asset('duotone/triangle-exclamation.svg') }}" alt="" class="es-duotone-icon es-icon-tone-brass h-4 w-4">
          <span>{{ $msg }}</span>
        </div>
      @endforeach
      </div>
    @endif

    @include('firewall.partials.create-form')

    <div class="vs-fw-inventory-head">
      <div>
        <p class="vs-fw-eyebrow">Rules</p>
        <h2>Review, pause, edit, or remove active rules.</h2>
      </div>
      <div class="vs-fw-tabs" aria-label="Rule type tabs">
        <span class="vs-fw-tab vs-fw-tab-active">Auto Rules</span>
        <span class="vs-fw-tab">Manual Firewall Rules</span>
      </div>
    </div>

    <form id="bulkDeleteForm" method="POST" action="{{ route('firewall.bulk_destroy') }}" class="vs-fw-inventory">
      @csrf
      @method('DELETE')

      @include('firewall.partials.ai-rules-table')
      @include('firewall.partials.manual-rules-table')
    </form>

    @include('firewall.partials.toggle-forms')
  </section>
@endsection
