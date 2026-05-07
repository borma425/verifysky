@php
  $planKey = (string) ($plan['key'] ?? '');
  $isCurrent = ((string) ($currentPlan['key'] ?? $tenant->plan) === $planKey);
  $isStarter = $planKey === 'starter';
  $limits = $plan['limits'] ?? [];
  $canCheckout = ! $readOnly && $canManageBilling && ! $isStarter;
@endphp

<div class="vs-billing-plan-card {{ $isCurrent ? 'vs-billing-plan-card-current' : '' }} {{ $isStarter ? 'vs-billing-plan-card-free' : '' }}" data-plan-key="{{ $planKey }}">
  <div class="vs-billing-plan-title-row">
    <div>
      <h3 class="vs-billing-plan-name">
        {{ $plan['name'] }}
        @if($isCurrent)
          <span class="vs-billing-current-badge">Current</span>
        @endif
      </h3>
      <div class="vs-billing-plan-price">${{ number_format((int) ($plan['price_monthly'] ?? 0)) }}/month</div>
    </div>

    @if($canCheckout)
      <form method="POST" action="{{ route('billing.checkout', $planKey) }}">
        @csrf
        <button type="submit" class="vs-billing-checkout {{ $isCurrent ? 'vs-billing-checkout-current' : '' }}">
          {{ $isCurrent ? 'Subscribe again' : 'Checkout' }}
        </button>
      </form>
    @else
      <span class="vs-billing-plan-action {{ $isCurrent ? 'vs-billing-checkout-current' : '' }}">
        @if($readOnly)
          Read Only
        @elseif($isStarter)
          {{ $isCurrent ? 'Current' : 'Included' }}
        @else
          View Only
        @endif
      </span>
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
