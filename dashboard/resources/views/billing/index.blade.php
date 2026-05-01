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

  <style>
    .vs-billing {
      --vs-bg: #0E131D;
      --vs-surface: #1B202A;
      --vs-surface-high: #252A35;
      --vs-surface-highest: #303540;
      --vs-surface-low: #171C26;
      --vs-surface-lowest: #090E18;
      --vs-primary: #FCB900;
      --vs-primary-soft: rgba(252, 185, 0, 0.10);
      --vs-outline: rgba(80, 69, 50, 0.22);
      --vs-outline-soft: rgba(80, 69, 50, 0.16);
      --vs-text: #DEE2F1;
      --vs-muted: #D4C4AB;
      --vs-success: #3BCF8E;
      --vs-danger: #D47B78;
      --vs-info: #22D6FF;
      color: var(--vs-text);
      display: grid;
      gap: 2rem;
    }

    .vs-billing * {
      letter-spacing: 0;
    }

    .vs-billing-meta {
      color: var(--vs-primary);
      font-size: 0.625rem;
      font-weight: 800;
      letter-spacing: 0.24em;
      text-transform: uppercase;
    }

    .vs-billing-title {
      color: #FFFFFF;
      font-size: 1.875rem;
      font-weight: 800;
      line-height: 1.15;
      margin-top: 0.25rem;
    }

    .vs-billing-copy {
      color: var(--vs-text);
      font-size: 0.875rem;
      line-height: 1.75;
      margin-top: 0.5rem;
      max-width: 48rem;
    }

    .vs-billing-header {
      align-items: end;
      display: grid;
      gap: 1rem;
      grid-template-columns: minmax(0, 7fr) minmax(22rem, 5fr);
    }

    .vs-billing-summary {
      align-items: start;
      background: var(--vs-surface);
      border-left: 4px solid var(--vs-primary);
      border-radius: 8px;
      display: flex;
      flex-wrap: wrap;
      gap: 1rem 2rem;
      padding: 1rem;
    }

    .vs-billing-label {
      color: var(--vs-muted);
      display: block;
      font-size: 0.75rem;
      letter-spacing: 0.08em;
      margin-bottom: 0.25rem;
      text-transform: uppercase;
    }

    .vs-billing-value {
      color: #FFFFFF;
      font-size: 0.875rem;
      font-weight: 700;
    }

    .vs-billing-value-muted {
      color: var(--vs-text);
      font-size: 0.875rem;
    }

    .vs-billing-alert {
      align-items: flex-start;
      background: var(--vs-surface-low);
      border-left: 2px solid currentColor;
      border-radius: 8px;
      display: flex;
      gap: 0.75rem;
      outline: 1px solid color-mix(in srgb, currentColor 20%, transparent);
      padding: 1rem;
    }

    .vs-billing-alert-danger {
      color: var(--vs-danger);
    }

    .vs-billing-alert-info {
      color: var(--vs-info);
    }

    .vs-billing-alert-text {
      color: #FFFFFF;
      font-size: 0.875rem;
      font-weight: 600;
      line-height: 1.65;
    }

    .vs-billing-grid {
      display: grid;
      gap: 2rem;
      grid-template-columns: minmax(0, 7fr) minmax(22rem, 5fr);
    }

    .vs-billing-panel,
    .vs-billing-plan-panel {
      background: var(--vs-surface);
      border-radius: 8px;
      outline: 1px solid var(--vs-outline);
      padding: 1.5rem;
    }

    .vs-billing-panel-head,
    .vs-billing-plan-head {
      align-items: flex-start;
      display: flex;
      gap: 1rem;
      justify-content: space-between;
      margin-bottom: 1.5rem;
    }

    .vs-billing-h2 {
      color: #FFFFFF;
      font-size: 1.25rem;
      font-weight: 800;
      line-height: 1.2;
    }

    .vs-billing-subcopy {
      color: var(--vs-muted);
      font-size: 0.875rem;
      line-height: 1.6;
      margin-top: 0.5rem;
    }

    .vs-billing-status {
      background: rgba(59, 207, 142, 0.10);
      border: 1px solid rgba(59, 207, 142, 0.30);
      border-radius: 4px;
      color: var(--vs-success);
      flex: 0 0 auto;
      font-size: 0.75rem;
      font-weight: 800;
      letter-spacing: 0.08em;
      padding: 0.35rem 0.65rem;
      text-transform: uppercase;
    }

    .vs-billing-metrics {
      display: grid;
      gap: 1rem;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      margin-bottom: 1.5rem;
    }

    .vs-billing-metric,
    .vs-billing-usage {
      background: var(--vs-surface-lowest);
      border-radius: 8px;
      outline: 1px solid var(--vs-outline);
      padding: 1rem;
    }

    .vs-billing-metric-value {
      color: #FFFFFF;
      display: block;
      font-size: 1.125rem;
      font-weight: 800;
      margin-top: 0.25rem;
    }

    .vs-billing-price {
      color: var(--vs-primary);
      display: block;
      font-size: 0.875rem;
      font-weight: 700;
      margin-top: 0.25rem;
    }

    .vs-billing-usage-list {
      display: grid;
      gap: 1rem;
      margin-bottom: 1.5rem;
    }

    .vs-billing-usage {
      background: #202632;
    }

    .vs-billing-usage-head {
      align-items: center;
      display: flex;
      gap: 1rem;
      justify-content: space-between;
      margin-bottom: 0.75rem;
    }

    .vs-billing-usage-title {
      color: #FFFFFF;
      font-size: 0.875rem;
      font-weight: 800;
    }

    .vs-billing-usage-value {
      color: var(--vs-muted);
      font-family: var(--font-mono, ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace);
      font-size: 0.75rem;
      white-space: nowrap;
    }

    .vs-billing-usage-value strong {
      color: #FFFFFF;
      font-weight: 800;
    }

    .vs-billing-progress {
      background: var(--vs-surface-lowest);
      border-radius: 999px;
      height: 0.5rem;
      overflow: hidden;
    }

    .vs-billing-progress-fill {
      border-radius: inherit;
      height: 100%;
    }

    .vs-billing-action-row {
      border-top: 1px solid var(--vs-surface-lowest);
      margin-top: 1.5rem;
      padding-top: 1.5rem;
    }

    .vs-billing-secondary-action {
      background: var(--vs-surface-highest);
      border-radius: 4px;
      color: #FFFFFF;
      font-size: 0.875rem;
      font-weight: 700;
      outline: 1px solid var(--vs-outline);
      padding: 0.5rem 1rem;
      transition: background-color 160ms ease;
    }

    .vs-billing-secondary-action:hover {
      background: #343944;
    }

    .vs-billing-owner-note {
      background: var(--vs-surface-lowest);
      border-radius: 8px;
      color: #FFE6B5;
      font-size: 0.875rem;
      margin-top: 1.5rem;
      outline: 1px solid rgba(252, 185, 0, 0.22);
      padding: 0.875rem 1rem;
    }

    .vs-billing-owner-note-inner {
      align-items: flex-start;
      display: flex;
      gap: 0.5rem;
    }

    .vs-billing-owner-note .es-duotone-icon {
      flex: 0 0 auto;
      margin-top: 0.1rem;
    }

    .vs-billing-plan-list {
      display: grid;
      gap: 1rem;
    }

    .vs-billing-plan-card {
      background: var(--vs-surface);
      border-radius: 8px;
      outline: 1px solid var(--vs-outline);
      overflow: hidden;
      padding: 1.25rem;
      position: relative;
      transition: background-color 160ms ease;
    }

    .vs-billing-plan-card:hover {
      background: var(--vs-surface-high);
    }

    .vs-billing-plan-card-current {
      background: var(--vs-surface-high);
      outline-color: var(--vs-primary);
    }

    .vs-billing-plan-card-current::after {
      background: linear-gradient(to bottom left, rgba(252, 185, 0, 0.20), transparent);
      content: "";
      height: 4rem;
      pointer-events: none;
      position: absolute;
      right: 0;
      top: 0;
      width: 4rem;
    }

    .vs-billing-plan-title-row {
      align-items: flex-start;
      display: flex;
      gap: 1rem;
      justify-content: space-between;
      margin-bottom: 1rem;
      position: relative;
      z-index: 1;
    }

    .vs-billing-plan-name {
      color: #FFFFFF;
      font-size: 1.125rem;
      font-weight: 800;
    }

    .vs-billing-plan-card-current .vs-billing-plan-name {
      color: var(--vs-primary);
    }

    .vs-billing-current-badge {
      background: var(--vs-primary-soft);
      border: 1px solid rgba(252, 185, 0, 0.30);
      border-radius: 4px;
      color: var(--vs-primary);
      font-size: 0.625rem;
      font-weight: 800;
      margin-left: 0.5rem;
      padding: 0.15rem 0.4rem;
      text-transform: uppercase;
      vertical-align: middle;
    }

    .vs-billing-plan-price {
      color: var(--vs-muted);
      font-size: 0.875rem;
      margin-top: 0.125rem;
    }

    .vs-billing-checkout {
      background: var(--vs-surface-lowest);
      border-radius: 4px;
      color: #FFFFFF;
      font-size: 0.75rem;
      font-weight: 800;
      letter-spacing: 0.08em;
      outline: 1px solid var(--vs-outline);
      padding: 0.4rem 0.75rem;
      position: relative;
      text-transform: uppercase;
      transition: background-color 160ms ease, color 160ms ease;
      z-index: 1;
    }

    .vs-billing-plan-card:hover .vs-billing-checkout {
      background: var(--vs-primary);
      color: #412D00;
      outline-color: transparent;
    }

    .vs-billing-checkout-current {
      background: linear-gradient(to bottom, #FFDC9C, var(--vs-primary));
      color: #412D00;
      outline: none;
      box-shadow: 0 0 15px rgba(252, 185, 0, 0.15);
    }

    .vs-billing-limit-grid {
      display: grid;
      gap: 0.5rem;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      position: relative;
      z-index: 1;
    }

    .vs-billing-limit {
      background: var(--vs-surface-lowest);
      border-radius: 4px;
      color: var(--vs-text);
      font-size: 0.75rem;
      padding: 0.5rem;
    }

    .vs-billing-plan-card-current .vs-billing-limit {
      border: 1px solid var(--vs-outline);
      color: #FFFFFF;
      font-weight: 700;
    }

    .vs-billing-limit-label {
      color: var(--vs-muted);
      display: block;
      margin-bottom: 0.125rem;
      opacity: 0.70;
    }

    @media (max-width: 1279px) {
      .vs-billing-header,
      .vs-billing-grid {
        grid-template-columns: 1fr;
      }
    }

    @media (max-width: 640px) {
      .vs-billing-title {
        font-size: 1.55rem;
      }

      .vs-billing-panel,
      .vs-billing-plan-panel {
        padding: 1rem;
      }

      .vs-billing-metrics,
      .vs-billing-limit-grid {
        grid-template-columns: 1fr;
      }

      .vs-billing-panel-head,
      .vs-billing-plan-title-row {
        flex-direction: column;
      }

      .vs-billing-usage-head {
        align-items: flex-start;
        flex-direction: column;
      }
    }

    .vs-billing {
      font-family: Manrope, Inter, var(--font-sans);
      gap: 2rem;
    }

    .vs-billing-header {
      display: block;
    }

    .vs-billing-meta {
      color: #FCB900;
      font-family: Inter, var(--font-sans);
      font-size: 0.8rem;
      letter-spacing: 0.12em;
    }

    .vs-billing-title {
      color: #FFFFFF;
      font-family: Manrope, var(--font-sans);
      font-size: 1.9rem;
      font-weight: 800;
      letter-spacing: -0.02em;
    }

    .vs-billing-copy {
      color: #DEE2F1;
      max-width: 48rem;
    }

    .vs-billing-summary {
      margin-top: 1.5rem;
      border-radius: 4px;
      border-left: 4px solid #FCB900;
      background: #1B202A;
      padding: 1rem;
    }

    .vs-billing-label,
    .vs-billing-limit-label {
      color: #D4C4AB;
      font-size: 0.72rem;
      font-weight: 700;
      letter-spacing: 0.08em;
      text-transform: uppercase;
    }

    .vs-billing-alert {
      border-radius: 4px;
      background: #171C26;
      padding: 1rem;
    }

    .vs-billing-grid {
      grid-template-columns: minmax(0, 7fr) minmax(22rem, 5fr);
    }

    .vs-billing-panel {
      border-radius: 8px;
      background: #1B202A;
      outline: 1px solid rgba(80, 69, 50, 0.16);
      padding: 1.5rem;
    }

    .vs-billing-plan-panel {
      background: transparent;
      outline: 0;
      padding: 0;
    }

    .vs-billing-h2 {
      color: #FFFFFF;
      font-family: Manrope, var(--font-sans);
      font-size: 1.25rem;
      font-weight: 800;
    }

    .vs-billing-subcopy {
      color: #D4C4AB;
    }

    .vs-billing-status {
      border-color: rgba(59, 207, 142, 0.3);
      border-radius: 4px;
      background: rgba(59, 207, 142, 0.1);
      color: #3BCF8E;
      font-size: 0.72rem;
    }

    .vs-billing-metrics {
      grid-template-columns: repeat(3, minmax(0, 1fr));
      margin-bottom: 2rem;
    }

    .vs-billing-metric,
    .vs-billing-usage,
    .vs-billing-limit {
      border-radius: 4px;
      background: #090E18;
      outline: 1px solid rgba(80, 69, 50, 0.16);
    }

    .vs-billing-metric-value,
    .vs-billing-usage-title,
    .vs-billing-plan-name {
      color: #FFFFFF;
    }

    .vs-billing-price {
      color: #FCB900;
    }

    .vs-billing-usage-list {
      gap: 1.5rem;
      margin-bottom: 2rem;
    }

    .vs-billing-usage {
      background: transparent;
      outline: 0;
      padding: 0;
    }

    .vs-billing-progress {
      height: 0.5rem;
      background: #090E18;
    }

    .vs-billing-action-row {
      border-top-color: #090E18;
    }

    .vs-billing-secondary-action {
      border-radius: 4px;
      background: #303540;
      outline-color: rgba(80, 69, 50, 0.22);
    }

    .vs-billing-owner-note {
      margin-top: 1rem;
      border-radius: 4px;
      background: transparent;
      color: #D4C4AB;
      outline: 0;
      padding-inline: 0.5rem;
    }

    .vs-billing-plan-list {
      gap: 1rem;
    }

    .vs-billing-plan-card {
      border-radius: 8px;
      background: #1B202A;
      outline: 1px solid rgba(80, 69, 50, 0.16);
      padding: 1.25rem;
    }

    .vs-billing-plan-card:hover {
      background: #252A35;
    }

    .vs-billing-plan-card-current {
      background: #252A35;
      outline-color: #FCB900;
    }

    .vs-billing-current-badge {
      border-color: rgba(252, 185, 0, 0.3);
      border-radius: 4px;
      background: rgba(252, 185, 0, 0.1);
      color: #FCB900;
    }

    .vs-billing-plan-price {
      color: #D4C4AB;
    }

    .vs-billing-checkout {
      border-radius: 4px;
      background: #090E18;
      outline: 1px solid rgba(80, 69, 50, 0.22);
    }

    .vs-billing-plan-card:hover .vs-billing-checkout,
    .vs-billing-checkout-current {
      background: linear-gradient(to bottom, #FFDC9C, #FCB900);
      color: #412D00;
      outline: 0;
      box-shadow: 0 0 15px rgba(252, 185, 0, 0.15);
    }

    .vs-billing-limit-grid {
      gap: 0.5rem;
    }

    .vs-billing-limit {
      color: #DEE2F1;
      padding: 0.5rem;
    }

    .vs-billing-plan-card-current .vs-billing-limit {
      border: 1px solid rgba(80, 69, 50, 0.22);
    }

    @media (max-width: 1279px) {
      .vs-billing-grid,
      .vs-billing-metrics {
        grid-template-columns: 1fr;
      }
    }
  </style>

  <section class="vs-billing es-animate">
    <div class="vs-billing-header">
      <div>
        <p class="vs-billing-meta">Customer Billing</p>
        <h1 class="vs-billing-title">Subscription Control</h1>
        <p class="vs-billing-copy">Manage your VerifySky plan, checkout new subscriptions, and monitor whether your protection is active or scheduled to downgrade.</p>
      </div>

      <div class="vs-billing-summary">
        <div>
          <span class="vs-billing-label">Plan</span>
          <span class="vs-billing-value">{{ $currentPlanName }} Plan</span>
        </div>
        <div>
          <span class="vs-billing-label">Limit Basis</span>
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
        <div class="vs-billing-alert-text">Billing tables are not available yet. Run the latest billing migrations before enabling customer payments.</div>
      </div>
    @else
      @if($activeGrant)
        <div class="vs-billing-alert vs-billing-alert-info">
          <img src="{{ asset('duotone/circle-info.svg') }}" alt="" class="es-duotone-icon es-icon-tone-brass h-5 w-5">
          <div class="vs-billing-alert-text">
            Bonus {{ strtoupper((string) $activeGrant->granted_plan_key) }} Allowance Active, temporary extra capacity until {{ $grantEndsAt?->format('Y-m-d H:i') }} UTC.
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
              <p class="vs-billing-subcopy">This panel reflects the last known PayPal subscription state for your account.</p>
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
              <span class="vs-billing-label">Renewal Window</span>
              <span class="vs-billing-metric-value font-mono">{{ $periodEndsAt ? $periodEndsAt->format('Y-m-d') : 'Not scheduled' }}</span>
              <span class="vs-billing-subcopy">{{ $subscription?->cancel_at_period_end ? 'Cancellation is queued for period end.' : 'Subscription remains active until PayPal changes state.' }}</span>
            </div>
            <div class="vs-billing-metric {{ $subscription?->cancel_at_period_end ? 'border-b-2 border-[#D47B78]' : '' }}">
              <span class="vs-billing-label">Status</span>
              <span class="vs-billing-metric-value {{ $subscription?->cancel_at_period_end ? 'text-[#D47B78]' : '' }}">
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
                  $usageColor = $usageCard['metric']['level'] === 'danger' ? '#D47B78' : ($usageCard['metric']['level'] === 'warning' ? '#FCB900' : '#3BCF8E');
                  $usageWidth = min(100, max(0, (int) $usageCard['metric']['percentage']));
                  $limitEquation = $billingTerms->billingMetricEquation($billingStatus, $usageCard['metric'], $usageCard['limit_key']);
                @endphp
                <div class="vs-billing-usage">
                  <div class="vs-billing-usage-head">
                    <span class="vs-billing-usage-title">{{ $usageCard['title'] }}</span>
                    <span class="vs-billing-usage-value">
                      <strong>{{ $usageCard['metric']['percentage'] }}%</strong>
                      <span class="mx-2 text-[#76859C]">-</span>
                      {{ $usageCard['metric']['formatted_used'] }} / {{ $usageCard['metric']['formatted_limit'] }}
                    </span>
                  </div>
                  <div class="vs-billing-progress">
                    <div class="vs-billing-progress-fill" style="width: {{ $usageWidth }}%; background-color: {{ $usageColor }}"></div>
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
              <h2 class="vs-billing-h2">Upgrade Or Change Plan</h2>
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
                        {{ $isCurrent ? 'Re-subscribe' : 'Checkout' }}
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
