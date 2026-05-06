@extends('layouts.app')

@section('content')
  @php
    $billingTerms = app(\App\ViewData\BillingTerminologyViewData::class);
    $subscriptionStatus = $subscription?->status ? ucfirst(str_replace('_', ' ', $subscription->status)) : 'No paid subscription';
    $periodEndsAt = $subscription?->current_period_ends_at?->utc();
    $planCards = $paidPlans ?? [];
    $grantEndsAt = $activeGrant?->ends_at?->utc();
    $currentPlanName = $currentPlan['name'] ?? ucfirst((string) $tenant->plan);
    $effectivePlanSource = $billingTerms->sourceLabel($billingStatus['effective_plan_source'] ?? 'baseline');
  @endphp

  <section class="vs-billing es-animate">
    <div class="vs-billing-header">
      <div>
        <p class="vs-billing-meta">Billing</p>
        <h1 class="vs-billing-title">Subscription</h1>
        <p class="vs-billing-copy">Manage your VerifySky plan, start checkout, and see whether your protection is active.</p>
      </div>

      <div class="vs-billing-summary">
        <div>
          <span class="vs-billing-label">Plan</span>
          <span class="vs-billing-value">{{ $currentPlanName }} Plan</span>
        </div>
        <div>
          <span class="vs-billing-label">Current access</span>
          <span class="vs-billing-value-muted">{{ $effectivePlanSource }}</span>
        </div>
        @if($periodEndsAt)
          <div>
            <span class="vs-billing-label">Paid Through</span>
            <span class="vs-billing-value-muted font-mono">{{ $periodEndsAt->format('Y-m-d H:i') }} UTC</span>
          </div>
        @endif
        @if($grantEndsAt)
          <div>
            <span class="vs-billing-label">Bonus</span>
            <span class="vs-billing-value-muted font-mono">Extra allowance until {{ $grantEndsAt->format('Y-m-d H:i') }} UTC</span>
          </div>
        @endif
      </div>
    </div>

    @if(! $billingStorageReady)
      <div class="vs-billing-alert vs-billing-alert-danger">
        <img src="{{ asset('duotone/triangle-exclamation.svg') }}" alt="" class="es-duotone-icon es-icon-tone-coral h-5 w-5">
        <div class="vs-billing-alert-text">Billing is not ready yet. Run the latest billing migrations before enabling payments.</div>
      </div>
    @else
      @if($activeGrant)
        <div class="vs-billing-alert vs-billing-alert-info">
          <img src="{{ asset('duotone/circle-info.svg') }}" alt="" class="es-duotone-icon es-icon-tone-brass h-5 w-5">
          <div class="vs-billing-alert-text">
            Bonus {{ strtoupper((string) $activeGrant->granted_plan_key) }} is active until {{ $grantEndsAt?->format('Y-m-d H:i') }} UTC.
            @if($activeGrant->reason)
              Reason: {{ $activeGrant->reason }}
            @endif
          </div>
        </div>
      @endif

      <div class="vs-billing-grid">
        <div class="vs-billing-panel">
          <div class="vs-billing-panel-head">
            <div>
              <h2 class="vs-billing-h2">Current Subscription</h2>
              <p class="vs-billing-subcopy">This shows your latest PayPal subscription status.</p>
            </div>
            <span class="vs-billing-status">{{ $grantEndsAt ? 'Bonus Active' : $subscriptionStatus }}</span>
          </div>

          <div class="vs-billing-metrics">
            <div class="vs-billing-metric">
              <span class="vs-billing-label">Plan</span>
              <span class="vs-billing-metric-value">{{ $currentPlanName }}</span>
              <span class="vs-billing-price">${{ number_format((int) ($currentPlan['price_monthly'] ?? 0)) }}/month</span>
            </div>
            <div class="vs-billing-metric">
              <span class="vs-billing-label">Renewal date</span>
              <span class="vs-billing-metric-value font-mono">{{ $periodEndsAt ? $periodEndsAt->format('Y-m-d') : 'Not scheduled' }}</span>
              <span class="vs-billing-subcopy">{{ $subscription?->cancel_at_period_end ? 'Cancellation is scheduled for the end of this period.' : 'Subscription stays active until PayPal changes it.' }}</span>
            </div>
            <div class="vs-billing-metric {{ $subscription?->cancel_at_period_end ? 'vs-billing-metric-warning' : '' }}">
              <span class="vs-billing-label">Status</span>
              <span class="vs-billing-metric-value {{ $subscription?->cancel_at_period_end ? 'vs-billing-text-danger' : '' }}">
                {{ $subscription?->cancel_at_period_end ? 'Cancellation queued' : ($grantEndsAt ? 'Bonus Active' : $subscriptionStatus) }}
              </span>
            </div>
          </div>

          @if($billingStatus)
            <div class="vs-billing-usage-list">
              @foreach([
                ['title' => 'Protected Sessions', 'metric' => $billingStatus['protected_sessions'], 'limit_key' => 'protected_sessions'],
                ['title' => 'Bot Fair Use', 'metric' => $billingStatus['bot_requests'], 'limit_key' => 'bot_fair_use'],
              ] as $usageCard)
                @php
                  $usageWidth = min(100, max(0, (int) $usageCard['metric']['percentage']));
                  $usageLevel = in_array($usageCard['metric']['level'], ['normal', 'warning', 'danger'], true) ? $usageCard['metric']['level'] : 'normal';
                  $limitEquation = $billingTerms->billingMetricEquation($billingStatus, $usageCard['metric'], $usageCard['limit_key']);
                @endphp
                <div class="vs-billing-usage">
                  <div class="vs-billing-usage-head">
                    <span class="vs-billing-usage-title">{{ $usageCard['title'] }}</span>
                    <span class="vs-billing-usage-value">
	                      <strong>{{ $usageCard['metric']['percentage'] }}%</strong>
	                      <span class="vs-billing-usage-separator mx-2">-</span>
	                      {{ $usageCard['metric']['formatted_used'] }} / {{ $usageCard['metric']['formatted_limit'] }}
                    </span>
                  </div>
	                  <div class="vs-billing-progress">
	                    <div class="vs-billing-progress-fill vs-billing-progress-fill-{{ $usageLevel }}" style="width: {{ $usageWidth }}%"></div>
	                  </div>
                  @include('partials.billing-limit-equation', ['equation' => $limitEquation])
                </div>
              @endforeach
            </div>
          @endif

          @if($subscription instanceof \App\Models\TenantSubscription && $canManageBilling && $subscription->status === \App\Models\TenantSubscription::STATUS_ACTIVE && ! $subscription->cancel_at_period_end)
            <form method="POST" action="{{ route('billing.subscription.cancel') }}" class="vs-billing-action-row" onsubmit="return confirm('Cancel auto-renewal for your current PayPal subscription?');">
              @csrf
              <button type="submit" class="vs-billing-secondary-action">Cancel At Period End</button>
            </form>
          @endif

          @if(! $canManageBilling)
            <div class="vs-billing-owner-note">
              <div class="vs-billing-owner-note-inner">
                <img src="{{ asset('duotone/lock.svg') }}" alt="" class="es-duotone-icon es-icon-tone-brass h-4 w-4">
                <span>You can view billing status, but only the account owner can start checkout or cancel the subscription.</span>
              </div>
            </div>
          @endif
        </div>

        <div class="vs-billing-plan-panel">
          <div class="vs-billing-plan-head">
            <div>
              <h2 class="vs-billing-h2">Upgrade or change plan</h2>
              <p class="vs-billing-subcopy">Paid plans are billed monthly through PayPal recurring subscriptions.</p>
            </div>
          </div>

          <div class="vs-billing-plan-list">
            @foreach($planCards as $plan)
              @php
                $isCurrent = ($currentPlan['key'] ?? $tenant->plan) === $plan['key'];
                $limits = $plan['limits'] ?? [];
              @endphp
              <div class="vs-billing-plan-card {{ $isCurrent ? 'vs-billing-plan-card-current' : '' }}">
                <div class="vs-billing-plan-title-row">
                  <div>
                    <h3 class="vs-billing-plan-name">
                      {{ $plan['name'] }}
                      @if($isCurrent)
                        <span class="vs-billing-current-badge">Current</span>
                      @endif
                    </h3>
                    <div class="vs-billing-plan-price">${{ number_format($plan['price_monthly']) }}/month</div>
                  </div>
                  @if($canManageBilling)
                    <form method="POST" action="{{ route('billing.checkout', $plan['key']) }}">
                      @csrf
                      <button type="submit" class="vs-billing-checkout {{ $isCurrent ? 'vs-billing-checkout-current' : '' }}">
                      {{ $isCurrent ? 'Subscribe again' : 'Checkout' }}
                      </button>
                    </form>
                  @endif
                </div>

                <div class="vs-billing-limit-grid">
                  <div class="vs-billing-limit">
                    <span class="vs-billing-limit-label">Domains</span>
                    {{ number_format((int) ($limits['domains'] ?? 0)) }}
                  </div>
                  <div class="vs-billing-limit">
                    <span class="vs-billing-limit-label">Firewall Rules</span>
                    {{ number_format((int) ($limits['custom_rules'] ?? 0)) }}
                  </div>
                  <div class="vs-billing-limit">
                    <span class="vs-billing-limit-label">Protected Sessions</span>
                    {{ number_format((int) ($limits['protected_sessions'] ?? 0)) }}
                  </div>
                  <div class="vs-billing-limit">
                    <span class="vs-billing-limit-label">Bot Fair Use</span>
                    {{ number_format((int) ($limits['bot_fair_use'] ?? 0)) }}
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
