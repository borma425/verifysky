@extends('layouts.customer-mirror')

@section('content')
  @php
    $billingTerms = app(\App\ViewData\BillingTerminologyViewData::class);
    $subscriptionStatus = $subscription?->status ? ucfirst(str_replace('_', ' ', $subscription->status)) : 'No paid subscription';
    $periodEndsAt = $subscription?->current_period_ends_at?->utc();
    $planCards = $planCards ?? [];
    $grantEndsAt = $activeGrant?->ends_at?->utc();
    $grantIsTrial = (string) ($activeGrant?->source ?? '') === 'trial';
    $grantLabel = $activeGrant ? $billingTerms->grantStatusText($activeGrant) : null;
    $fallbackPlanName = (string) $tenant->plan === 'starter' ? 'Free' : ucfirst((string) $tenant->plan);
    $currentPlanName = $currentPlan['name'] ?? $fallbackPlanName;
    $effectivePlanSource = $billingTerms->sourceLabel($billingStatus['effective_plan_source'] ?? 'baseline');
  @endphp

  <section class="vs-billing es-animate">
    <div class="vs-billing-header">
      <div>
        <p class="vs-billing-meta">Billing</p>
        <h1 class="vs-billing-title">Subscription Summary</h1>
        <p class="vs-billing-copy">Customer billing status rendered in read-only mode.</p>
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
        <div>
          <span class="vs-billing-label">Subscription Window</span>
          <span class="vs-billing-value-muted font-mono">{{ $periodEndsAt ? $periodEndsAt->format('Y-m-d H:i').' UTC' : 'Not scheduled' }}</span>
        </div>
        @if($grantEndsAt)
          <div>
            <span class="vs-billing-label">{{ $grantIsTrial ? 'Trial' : 'Bonus' }}</span>
            <span class="vs-billing-value-muted font-mono">{{ $grantEndsAt->format('Y-m-d H:i') }} UTC</span>
          </div>
        @endif
      </div>
    </div>

    @if(! $billingStorageReady)
      <div class="vs-billing-alert vs-billing-alert-danger">
        <img src="{{ asset('duotone/triangle-exclamation.svg') }}" alt="" class="es-duotone-icon es-icon-tone-coral h-5 w-5">
        <div class="vs-billing-alert-text">Billing tables are not available yet.</div>
      </div>
    @else
      @if($activeGrant && $grantIsTrial)
        <div class="vs-billing-alert vs-billing-alert-success">
          <img src="{{ asset('duotone/shield-check.svg') }}" alt="" class="es-duotone-icon es-icon-tone-success h-5 w-5">
          <div class="vs-billing-alert-text">Pro trial active. {{ $currentPlanName }} protection is active until {{ $grantEndsAt?->format('Y-m-d H:i') }} UTC.</div>
        </div>
      @elseif($activeGrant)
        <div class="vs-billing-alert vs-billing-alert-info">
          <img src="{{ asset('duotone/circle-info.svg') }}" alt="" class="es-duotone-icon es-icon-tone-brass h-5 w-5">
          <div class="vs-billing-alert-text">
            {{ $grantLabel }} is active until {{ $grantEndsAt?->format('Y-m-d H:i') }} UTC.
            Reason: {{ $activeGrant->reason ?: 'No reason provided.' }}
          </div>
        </div>
      @endif

      <div class="vs-billing-grid">
        <div class="vs-billing-panel">
          <div class="vs-billing-panel-head">
            <div>
              <h2 class="vs-billing-h2">Current Subscription</h2>
              <p class="vs-billing-subcopy">Latest customer PayPal subscription and usage state.</p>
            </div>
            <span class="vs-billing-status vs-billing-status-readonly">Read Only</span>
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
                {{ $subscription?->cancel_at_period_end ? 'Cancellation queued' : ($grantEndsAt ? ($grantIsTrial ? 'Trial Active' : 'Bonus Active') : $subscriptionStatus) }}
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

          <div class="vs-billing-owner-note">
            <div class="vs-billing-owner-note-inner">
              <img src="{{ asset('duotone/lock.svg') }}" alt="" class="es-duotone-icon es-icon-tone-brass h-4 w-4">
              <span>This admin mirror is read-only. Billing actions must be performed from the customer account owner flow.</span>
            </div>
          </div>
        </div>

        <div class="vs-billing-plan-panel">
          <div class="vs-billing-plan-head">
            <div>
              <h2 class="vs-billing-h2">Available Plans</h2>
              <p class="vs-billing-subcopy">Free and paid plan limits shown without checkout actions.</p>
            </div>
          </div>

          <div class="vs-billing-plan-list">
            @foreach($planCards as $plan)
              @include('billing.partials.plan-card', [
                'plan' => $plan,
                'currentPlan' => $currentPlan,
                'tenant' => $tenant,
                'canManageBilling' => false,
                'readOnly' => true,
              ])
            @endforeach
          </div>
        </div>
      </div>
    @endif
  </section>
@endsection
