@extends('layouts.customer-mirror')

@section('content')
  @php
    $billingTerms = app(\App\ViewData\BillingTerminologyViewData::class);
    $periodEndsAt = $subscription?->current_period_ends_at?->utc();
    $grantEndsAt = $activeGrant?->ends_at?->utc();
  @endphp

  @if(! $billingStorageReady)
    <div class="es-card p-5 text-sm text-[#D7E1F5]">
      Billing tables are not available yet.
    </div>
  @else
    <div class="grid gap-4 xl:grid-cols-[1.35fr,0.95fr]">
      <div class="es-card p-5 md:p-6">
        <div class="mb-5">
          <h2 class="text-lg font-bold text-[#FFFFFF]">Subscription Summary</h2>
          <p class="mt-1 text-sm text-[#AEB9CC]">Customer billing status rendered in read-only mode.</p>
        </div>

        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
          <div class="rounded-xl border border-white/10 bg-[#202632] p-4">
            <div class="text-[10px] uppercase tracking-[0.22em] text-[#76859C]">Current Plan</div>
            <div class="mt-2 text-xl font-bold text-[#FFFFFF]">{{ $currentPlan['name'] ?? ucfirst((string) $tenant->plan) }}</div>
            <div class="mt-1 text-sm text-[#AEB9CC]">Limit Basis: {{ $billingTerms->sourceLabel($billingStatus['effective_plan_source'] ?? 'baseline') }}</div>
          </div>
          <div class="rounded-xl border border-white/10 bg-[#202632] p-4">
            <div class="text-[10px] uppercase tracking-[0.22em] text-[#76859C]">Subscription Window</div>
            <div class="mt-2 text-xl font-bold text-[#FFFFFF]">{{ $periodEndsAt ? $periodEndsAt->format('Y-m-d') : 'Not scheduled' }}</div>
            <div class="mt-1 text-sm text-[#AEB9CC]">{{ $subscription?->status ? ucfirst(str_replace('_', ' ', $subscription->status)) : 'No paid subscription' }}</div>
          </div>
        </div>

        @if($billingStatus)
          <div class="mt-5 grid grid-cols-1 gap-4 md:grid-cols-2">
            @foreach([
              ['title' => 'Protected Sessions', 'metric' => $billingStatus['protected_sessions'], 'limit_key' => 'protected_sessions'],
              ['title' => 'Bot Fair Use', 'metric' => $billingStatus['bot_requests'], 'limit_key' => 'bot_fair_use'],
            ] as $usageCard)
              @php
                $limitEquation = $billingTerms->billingMetricEquation($billingStatus, $usageCard['metric'], $usageCard['limit_key']);
              @endphp
              <div class="rounded-xl border border-white/10 bg-[#202632] p-4">
                <div class="flex items-center justify-between gap-3">
                  <div class="text-sm font-semibold text-[#FFFFFF]">{{ $usageCard['title'] }}</div>
                  <span class="rounded-full border border-white/10 px-2 py-1 text-[11px] font-bold text-[#D7E1F5]">{{ $usageCard['metric']['percentage'] }}%</span>
                </div>
                <div class="mt-3 text-xl font-bold text-[#FFFFFF]">{{ $usageCard['metric']['formatted_used'] }} <span class="text-sm text-[#AEB9CC]">/ {{ $usageCard['metric']['formatted_limit'] }}</span></div>
                @include('partials.billing-limit-equation', ['equation' => $limitEquation, 'class' => 'mt-3'])
              </div>
            @endforeach
          </div>
        @endif
      </div>

      <div class="es-card p-5 md:p-6">
        <h2 class="text-lg font-bold text-[#FFFFFF]">Bonus Allowance</h2>
        @if($activeGrant)
          <div class="mt-4 rounded-xl border border-[#FCB900]/20 bg-[#FCB900]/10 p-4 text-sm text-[#FFE6B5]">
            <div class="font-bold text-white">Bonus {{ strtoupper((string) $activeGrant->granted_plan_key) }} Allowance Active</div>
            <div class="mt-2">Ends at {{ $grantEndsAt?->format('Y-m-d H:i') }} UTC</div>
            <div class="mt-2">Reason: {{ $activeGrant->reason ?: 'No reason provided.' }}</div>
          </div>
        @else
          <div class="mt-4 rounded-xl border border-white/10 bg-[#202632] p-4 text-sm text-[#D7E1F5]">
            No active bonus allowance for this user.
          </div>
        @endif
      </div>
    </div>
  @endif
@endsection
