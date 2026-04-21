@extends('layouts.app')

@section('content')
  @php
    $subscriptionStatus = $subscription?->status ? ucfirst(str_replace('_', ' ', $subscription->status)) : 'No paid subscription';
    $periodEndsAt = $subscription?->current_period_ends_at?->utc();
    $planCards = $paidPlans ?? [];
    $grantEndsAt = $activeGrant?->ends_at?->utc();
  @endphp

  <section class="es-animate">
    <div class="es-page-header">
      <div class="flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
        <div>
          <p class="text-[10px] uppercase tracking-[0.24em] text-[#76859C]">Customer Billing</p>
          <h1 class="es-page-title text-2xl font-extrabold">Subscription Control</h1>
          <p class="mt-2 max-w-3xl text-sm text-[#AEB9CC]">Manage your VerifySky plan, checkout new subscriptions, and monitor whether your protection is active or scheduled to downgrade.</p>
        </div>
        <div class="rounded-lg border border-white/10 bg-[#202632] px-4 py-3 text-xs text-[#D7E1F5]">
          <div class="font-bold text-[#FFFFFF]">{{ $currentPlan['name'] ?? ucfirst((string) $tenant->plan) }} Plan</div>
          <div class="mt-1">Current plan source: {{ ucfirst(str_replace('_', ' ', $billingStatus['effective_plan_source'] ?? 'baseline')) }}</div>
          @if($periodEndsAt)
            <div class="mt-1 text-[#AEB9CC]">Paid through {{ $periodEndsAt->format('Y-m-d H:i') }} UTC</div>
          @endif
          @if($grantEndsAt)
            <div class="mt-1 text-[#FCB900]">Manual grant active until {{ $grantEndsAt->format('Y-m-d H:i') }} UTC</div>
          @endif
        </div>
      </div>
    </div>

    @if(! $billingStorageReady)
      <div class="es-card mt-6 p-5 text-sm text-[#D7E1F5]">
        Billing tables are not available yet. Run the latest billing migrations before enabling customer payments.
      </div>
    @else
      @if($activeGrant)
        <div class="es-card mt-6 border border-[#FCB900]/20 bg-[#FCB900]/10 p-5 text-sm text-[#FFE6B5]">
          <div class="font-bold text-[#FFFFFF]">Manual {{ strtoupper((string) $activeGrant->granted_plan_key) }} Grant Active</div>
          <div class="mt-2">This tenant is temporarily running on an admin-issued grant until {{ $grantEndsAt?->format('Y-m-d H:i') }} UTC.</div>
          @if($activeGrant->reason)
            <div class="mt-2 text-[#F9D58A]">Reason: {{ $activeGrant->reason }}</div>
          @endif
        </div>
      @endif

      <div class="mt-6 grid grid-cols-1 gap-4 xl:grid-cols-[1.35fr,0.95fr]">
        <div class="es-card p-5 md:p-6">
          <div class="mb-5 flex items-center justify-between gap-3">
            <div>
              <h2 class="text-lg font-bold text-[#FFFFFF]">Current Subscription</h2>
              <p class="mt-1 text-sm text-[#AEB9CC]">This panel reflects the last known PayPal subscription state for your tenant.</p>
            </div>
            <span class="rounded-full border border-[#FCB900]/30 bg-[#FCB900]/12 px-3 py-1 text-[11px] font-bold uppercase tracking-[0.16em] text-[#FFE6B5]">
              {{ $grantEndsAt ? 'Manual Grant Active' : $subscriptionStatus }}
            </span>
          </div>

          <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
            <div class="rounded-xl border border-white/10 bg-[#202632] p-4">
              <div class="text-[10px] uppercase tracking-[0.22em] text-[#76859C]">Plan</div>
              <div class="mt-2 text-xl font-bold text-[#FFFFFF]">{{ $currentPlan['name'] ?? ucfirst((string) $tenant->plan) }}</div>
              <div class="mt-1 text-sm text-[#AEB9CC]">${{ number_format((int) ($currentPlan['price_monthly'] ?? 0)) }}/month</div>
            </div>
            <div class="rounded-xl border border-white/10 bg-[#202632] p-4">
              <div class="text-[10px] uppercase tracking-[0.22em] text-[#76859C]">Renewal Window</div>
              <div class="mt-2 text-xl font-bold text-[#FFFFFF]">
                {{ $periodEndsAt ? $periodEndsAt->format('Y-m-d') : 'Not scheduled' }}
              </div>
              <div class="mt-1 text-sm text-[#AEB9CC]">
                {{ $subscription?->cancel_at_period_end ? 'Cancellation is queued for period end.' : 'Subscription remains active until PayPal changes state.' }}
              </div>
            </div>
          </div>

          @if($billingStatus)
            <div class="mt-5 grid grid-cols-1 gap-4 md:grid-cols-2">
              @foreach([
                ['title' => 'Protected Sessions', 'metric' => $billingStatus['protected_sessions']],
                ['title' => 'Bot Fair Use', 'metric' => $billingStatus['bot_requests']],
              ] as $usageCard)
                <div class="rounded-xl border border-white/10 bg-[#202632] p-4">
                  <div class="flex items-center justify-between gap-3">
                    <div class="text-sm font-semibold text-[#FFFFFF]">{{ $usageCard['title'] }}</div>
                    <span class="rounded-full border border-white/10 px-2 py-1 text-[11px] font-bold text-[#D7E1F5]">{{ $usageCard['metric']['percentage'] }}%</span>
                  </div>
                  <div class="mt-3 text-xl font-bold text-[#FFFFFF]">{{ $usageCard['metric']['formatted_used'] }} <span class="text-sm text-[#AEB9CC]">/ {{ $usageCard['metric']['formatted_limit'] }}</span></div>
                  <div class="mt-2 h-2 overflow-hidden rounded-full bg-white/8">
                    <div class="h-full rounded-full {{ $usageCard['metric']['level'] === 'danger' ? 'bg-[#D47B78]' : ($usageCard['metric']['level'] === 'warning' ? 'bg-[#FCB900]' : 'bg-[#3BCF8E]') }}" style="width: {{ $usageCard['metric']['percentage'] }}%"></div>
                  </div>
                </div>
              @endforeach
            </div>
          @endif

          @if($subscription instanceof \App\Models\TenantSubscription && $canManageBilling && $subscription->status === \App\Models\TenantSubscription::STATUS_ACTIVE && ! $subscription->cancel_at_period_end)
            <form method="POST" action="{{ route('billing.subscription.cancel') }}" class="mt-5" onsubmit="return confirm('Cancel auto-renewal for your current PayPal subscription?');">
              @csrf
              <button type="submit" class="es-btn es-btn-secondary">Cancel At Period End</button>
            </form>
          @endif

          @if(! $canManageBilling)
            <div class="mt-5 rounded-xl border border-[#FCB900]/20 bg-[#FCB900]/10 px-4 py-3 text-sm text-[#FFE6B5]">
              You can view billing status, but only the tenant owner can start checkout or cancel the subscription.
            </div>
          @endif
        </div>

        <div class="es-card p-5 md:p-6">
          <div class="mb-5">
            <h2 class="text-lg font-bold text-[#FFFFFF]">Upgrade Or Change Plan</h2>
            <p class="mt-1 text-sm text-[#AEB9CC]">Paid plans are billed monthly through PayPal recurring subscriptions.</p>
          </div>

          <div class="space-y-4">
            @foreach($planCards as $plan)
              @php
                $isCurrent = ($currentPlan['key'] ?? $tenant->plan) === $plan['key'];
                $limits = $plan['limits'] ?? [];
              @endphp
              <div class="rounded-xl border border-white/10 bg-[#202632] p-4">
                <div class="flex items-start justify-between gap-4">
                  <div>
                    <div class="flex items-center gap-2">
                      <h3 class="text-lg font-bold text-[#FFFFFF]">{{ $plan['name'] }}</h3>
                      @if($isCurrent)
                        <span class="rounded-full border border-[#3BCF8E]/30 bg-[#3BCF8E]/10 px-2 py-1 text-[10px] font-bold uppercase tracking-[0.16em] text-[#B6F7D7]">Current</span>
                      @endif
                    </div>
                    <div class="mt-1 text-sm text-[#AEB9CC]">${{ number_format($plan['price_monthly']) }}/month</div>
                  </div>
                  @if($canManageBilling)
                    <form method="POST" action="{{ route('billing.checkout', $plan['key']) }}">
                      @csrf
                      <button type="submit" class="es-btn {{ $isCurrent ? 'es-btn-secondary' : '' }}">{{ $isCurrent ? 'Re-subscribe' : 'Checkout' }}</button>
                    </form>
                  @endif
                </div>
                <div class="mt-4 grid grid-cols-2 gap-3 text-xs text-[#D7E1F5]">
                  <div class="rounded-lg border border-white/8 bg-[#171C26] px-3 py-2">
                    <div class="text-[10px] uppercase tracking-[0.18em] text-[#76859C]">Domains</div>
                    <div class="mt-1 font-bold">{{ number_format((int) ($limits['domains'] ?? 0)) }}</div>
                  </div>
                  <div class="rounded-lg border border-white/8 bg-[#171C26] px-3 py-2">
                    <div class="text-[10px] uppercase tracking-[0.18em] text-[#76859C]">Firewall Rules</div>
                    <div class="mt-1 font-bold">{{ number_format((int) ($limits['custom_rules'] ?? 0)) }}</div>
                  </div>
                  <div class="rounded-lg border border-white/8 bg-[#171C26] px-3 py-2">
                    <div class="text-[10px] uppercase tracking-[0.18em] text-[#76859C]">Protected Sessions</div>
                    <div class="mt-1 font-bold">{{ number_format((int) ($limits['protected_sessions'] ?? 0)) }}</div>
                  </div>
                  <div class="rounded-lg border border-white/8 bg-[#171C26] px-3 py-2">
                    <div class="text-[10px] uppercase tracking-[0.18em] text-[#76859C]">Bot Fair Use</div>
                    <div class="mt-1 font-bold">{{ number_format((int) ($limits['bot_fair_use'] ?? 0)) }}</div>
                  </div>
                </div>
              </div>
            @endforeach
          </div>
        </div>
      </div>
    @endif
  </section>
@endsection
