@extends('layouts.app')

@section('content')
  @php
    $billingTerms = app(\App\ViewData\BillingTerminologyViewData::class);
    $subscriptionStatus = $subscription?->status ? ucfirst(str_replace('_', ' ', $subscription->status)) : 'No paid subscription';
    $periodEndsAt = $subscription?->current_period_ends_at?->utc();
    $planCards = $planCards ?? $paidPlans ?? [];
    $grantEndsAt = $activeGrant?->ends_at?->utc();
    $grantIsTrial = (string) ($activeGrant?->source ?? '') === 'trial';
    $grantLabel = $activeGrant ? $billingTerms->grantStatusText($activeGrant) : null;
    $fallbackPlanName = (string) $tenant->plan === 'starter' ? 'Free' : ucfirst((string) $tenant->plan);
    $currentPlanName = $currentPlan['name'] ?? $fallbackPlanName;
    $effectivePlanSource = $billingTerms->sourceLabel($billingStatus['effective_plan_source'] ?? 'baseline');
    $formatCloudflareMoney = static function (float $amount): string {
      return $amount > 0 && $amount < 0.01 ? '< $0.01' : '$'.number_format($amount, 2);
    };
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
            <span class="vs-billing-label">{{ $grantIsTrial ? 'Trial' : 'Bonus' }}</span>
            <span class="vs-billing-value-muted font-mono">{{ $grantIsTrial ? 'Pro trial until' : 'Extra allowance until' }} {{ $grantEndsAt->format('Y-m-d H:i') }} UTC</span>
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
      @if($activeGrant && $grantIsTrial)
        <div class="vs-billing-alert vs-billing-alert-success">
          <img src="{{ asset('duotone/shield-check.svg') }}" alt="" class="es-duotone-icon es-icon-tone-success h-5 w-5">
          <div class="vs-billing-alert-body">
            <div class="vs-billing-alert-text">Pro trial active. {{ $currentPlanName }} protection is active until {{ $grantEndsAt?->format('Y-m-d H:i') }} UTC.</div>
            <a href="{{ route('billing.index') }}" class="vs-billing-inline-action">Upgrade to keep Pro</a>
          </div>
        </div>
      @elseif($activeGrant)
        <div class="vs-billing-alert vs-billing-alert-info">
          <img src="{{ asset('duotone/circle-info.svg') }}" alt="" class="es-duotone-icon es-icon-tone-brass h-5 w-5">
          <div class="vs-billing-alert-text">
            {{ $grantLabel }} is active until {{ $grantEndsAt?->format('Y-m-d H:i') }} UTC.
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
            <span class="vs-billing-status">{{ $grantEndsAt ? ($grantIsTrial ? 'Trial Active' : 'Bonus Active') : $subscriptionStatus }}</span>
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

          @if($cloudflareCosts)
            @php
              $costSummary = $cloudflareCosts['summary'] ?? [];
              $domainCosts = $cloudflareCosts['domains'] ?? [];
              $resourceCosts = $cloudflareCosts['resources'] ?? [];
              $lastSyncedAt = $cloudflareCosts['last_synced_at'] ?? null;
            @endphp
            <div class="mt-6 rounded-lg border border-cyan-300/20 bg-white/[0.03] p-4">
              <div class="flex flex-col gap-2 md:flex-row md:items-start md:justify-between">
                <div>
                  <h2 class="vs-billing-h2">Cloudflare Resource Cost</h2>
                  <p class="vs-billing-subcopy" title="Estimated from edge usage; final invoice reconciliation may differ slightly.">
                    Estimated from edge usage. Final invoice reconciliation may differ slightly.
                  </p>
                </div>
                <div class="text-right">
                  <div class="text-xs font-bold uppercase tracking-[0.16em] text-[#7F8BA0]">This cycle</div>
                  <div class="text-xl font-bold text-white">{{ $formatCloudflareMoney((float) ($costSummary['estimated_cost_usd'] ?? 0)) }}</div>
                  @if($lastSyncedAt)
                    <div class="text-xs text-sky-100/60">Synced {{ $lastSyncedAt->format('Y-m-d H:i') }} UTC</div>
                  @endif
                </div>
              </div>

              <div class="mt-4 grid gap-2 md:grid-cols-4">
                <div class="rounded-md border border-white/10 bg-white/[0.03] px-3 py-2">
                  <div class="vs-billing-label">Workers</div>
                  <div class="font-semibold text-white">{{ $resourceCosts['workers'] ?? '$0.00' }}</div>
                </div>
                <div class="rounded-md border border-white/10 bg-white/[0.03] px-3 py-2">
                  <div class="vs-billing-label">D1</div>
                  <div class="font-semibold text-white">{{ $resourceCosts['d1'] ?? '$0.00' }}</div>
                </div>
                <div class="rounded-md border border-white/10 bg-white/[0.03] px-3 py-2">
                  <div class="vs-billing-label">KV</div>
                  <div class="font-semibold text-white">{{ $resourceCosts['kv'] ?? '$0.00' }}</div>
                </div>
                <div class="rounded-md border border-white/10 bg-white/[0.03] px-3 py-2">
                  <div class="vs-billing-label">Telemetry</div>
                  <div class="font-semibold text-white">{{ $resourceCosts['wae'] ?? '$0.00' }}</div>
                </div>
              </div>

              <div class="mt-4 overflow-x-auto">
                <table class="es-table min-w-[720px]">
                  <thead>
                  <tr>
                    <th>Domain</th>
                    <th>Requests</th>
                    <th>D1 rows</th>
                    <th>KV ops</th>
                    <th>Estimated cost</th>
                  </tr>
                  </thead>
                  <tbody>
                  @forelse($domainCosts as $domainCost)
                    <tr>
                      <td class="font-semibold text-white">{{ $domainCost['domain_name'] }}</td>
                      <td>{{ number_format((int) ($domainCost['requests'] ?? 0)) }}</td>
                      <td>{{ number_format((int) ($domainCost['d1_rows_read'] ?? 0) + (int) ($domainCost['d1_rows_written'] ?? 0)) }}</td>
                      <td>{{ number_format((int) ($domainCost['kv_operations'] ?? 0)) }}</td>
                      <td class="font-semibold text-white">{{ $formatCloudflareMoney((float) ($domainCost['estimated_cost_usd'] ?? 0)) }}</td>
                    </tr>
                  @empty
                    <tr>
                      <td colspan="5" class="py-4 text-center text-sky-100/60">No Cloudflare cost data has synced for this billing cycle yet.</td>
                    </tr>
                  @endforelse
                  </tbody>
                </table>
              </div>
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
              @include('billing.partials.plan-card', [
                'plan' => $plan,
                'currentPlan' => $currentPlan,
                'tenant' => $tenant,
                'canManageBilling' => $canManageBilling,
                'readOnly' => false,
              ])
            @endforeach
          </div>
        </div>
      </div>
    @endif
  </section>
@endsection
