@extends('layouts.admin')

@section('content')
  @php
    $billingTerms = app(\App\ViewData\BillingTerminologyViewData::class);
  @endphp

  <div class="mb-6 flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
    <div>
      <h1 class="es-title">Users</h1>
      <p class="es-subtitle mt-2">Manage users, domains, billing, bonuses, and usage.</p>
    </div>
    <a href="{{ route('admin.overview') }}" class="es-btn es-btn-secondary">Overview</a>
  </div>

  @unless($billingAvailable)
    <div class="mb-4 rounded-lg border border-amber-400/35 bg-amber-500/15 px-4 py-3 text-sm text-amber-100">
      <strong>Billing setup is pending.</strong> Run billing migrations before changing bonuses, subscriptions, or usage.
    </div>
  @endunless

  <div class="es-card p-0">
    <div class="overflow-x-auto">
      <table class="es-table min-w-[1180px]">
        <thead>
        <tr>
          <th>User</th>
          <th>Plan Limit</th>
          <th>Total Limit</th>
          <th>Billing Cycle</th>
          <th>Bonus</th>
          <th>Domains</th>
          <th>Cloudflare Cost</th>
          <th>Actions</th>
        </tr>
        </thead>
        <tbody>
        @forelse($tenantRows as $row)
          @php
            $tenant = $row['tenant'];
            $billing = $row['billing'];
            $grant = $row['active_grant'];
            $cf = $row['cloudflare_cost'] ?? [];
            $source = $billingTerms->sourceLabel($row['effective_plan']['source'] ?? 'baseline');
          @endphp
          <tr>
            <td>
              <div class="font-semibold text-white">{{ $tenant->name }}</div>
              <div class="text-xs text-sky-100/60">#{{ $tenant->id }} / {{ $tenant->slug }}</div>
            </td>
            <td>{{ $row['baseline_plan']['name'] ?? ucfirst((string) $tenant->plan) }}</td>
            <td>
              <div class="font-semibold text-white">{{ $row['effective_plan']['name'] ?? 'Free' }}</div>
              <div class="text-xs text-sky-100/60">{{ $source }}</div>
            </td>
            <td>
              @if($billing)
                <div class="font-semibold {{ $billing['is_pass_through'] ? 'text-amber-200' : 'text-emerald-200' }}">{{ str_replace('_', ' ', $billing['quota_status']) }}</div>
                <div class="text-xs text-sky-100/70">Sessions: {{ $billing['protected_sessions']['formatted_used'] }} / {{ $billing['protected_sessions']['formatted_limit'] }}</div>
                @include('partials.billing-limit-equation', ['equation' => $billingTerms->billingMetricEquation($billing, $billing['protected_sessions'], 'protected_sessions'), 'class' => 'mt-1'])
                <div class="text-xs text-sky-100/70">Bots: {{ $billing['bot_requests']['formatted_used'] }} / {{ $billing['bot_requests']['formatted_limit'] }}</div>
                @include('partials.billing-limit-equation', ['equation' => $billingTerms->billingMetricEquation($billing, $billing['bot_requests'], 'bot_fair_use'), 'class' => 'mt-1'])
              @else
                <span class="text-sm text-amber-100">Run billing migrations first</span>
              @endif
            </td>
            <td>
              @if($grant)
                <div class="font-semibold text-white">{{ strtoupper($grant['granted_plan_key']) }}</div>
                <div class="text-xs text-sky-100/70">{{ $grant['reason'] ?: 'Bonus allowance' }}</div>
                <form method="POST" action="{{ route('admin.tenants.manual_grants.revoke', [$tenant, $grant['id']]) }}" class="mt-2">
                  @csrf
                  <button class="text-xs font-semibold text-rose-200 hover:text-rose-100" type="submit">Revoke Bonus</button>
                </form>
              @else
                <span class="text-sm text-sky-100/60">None</span>
              @endif
            </td>
            <td>
              <div class="font-semibold text-white">{{ number_format($row['domains_count']) }}</div>
              <div class="text-xs text-sky-100/60">{{ $row['members_count'] ?? 0 }} member(s)</div>
            </td>
            <td>
              <div class="font-semibold text-white">${{ number_format((float) ($cf['summary']['estimated_cost_usd'] ?? 0), 2) }}</div>
              <div class="text-xs text-sky-100/60">Revenue: ${{ number_format((float) ($cf['monthly_revenue_usd'] ?? 0), 2) }}</div>
              <div class="text-xs {{ (($cf['profit_usd'] ?? 0) < 0) ? 'text-rose-200' : 'text-emerald-200' }}">
                Profit: ${{ number_format((float) ($cf['profit_usd'] ?? 0), 2) }}
                @if(($cf['margin_percentage'] ?? null) !== null)
                  / {{ $cf['margin_percentage'] }}%
                @endif
              </div>
              <div class="mt-1 text-xs font-semibold {{ $tenant->is_vip ? 'text-cyan-200' : 'text-sky-100/45' }}">
                {{ $tenant->is_vip ? 'VIP visible' : 'Hidden from customer' }}
              </div>
            </td>
            <td>
              <div class="flex flex-wrap gap-2">
                <a href="{{ route('admin.tenants.show', $tenant) }}" class="es-btn es-btn-secondary px-3 py-2 text-xs">Manage User</a>
                <form method="POST" action="{{ route('admin.tenants.force_cycle_reset', $tenant) }}">
                  @csrf
                  <button class="es-btn px-3 py-2 text-xs" type="submit">Force Reset</button>
                </form>
                <form method="POST" action="{{ route('admin.tenants.vip.update', $tenant) }}">
                  @csrf
                  <input type="hidden" name="is_vip" value="{{ $tenant->is_vip ? 0 : 1 }}">
                  <button class="es-btn es-btn-secondary px-3 py-2 text-xs" type="submit">{{ $tenant->is_vip ? 'Hide Cost' : 'VIP Cost' }}</button>
                </form>
              </div>
              @if($billingAvailable)
                <form method="POST" action="{{ route('admin.tenants.manual_grants.store', $tenant) }}" class="mt-3 grid gap-2">
                  @csrf
                  <div class="flex gap-2">
                    <select name="plan_key" class="es-input h-9 text-xs">
                      @foreach($grantablePlans as $plan)
                        <option value="{{ $plan['key'] }}">{{ $plan['name'] }}</option>
                      @endforeach
                    </select>
                    <input name="duration_days" class="es-input h-9 w-20 text-xs" type="number" min="1" max="365" value="14">
                  </div>
                  <input name="reason" class="es-input h-9 text-xs" placeholder="Reason">
                  <button class="text-left text-xs font-semibold text-cyan-200 hover:text-cyan-100" type="submit">Add Bonus</button>
                </form>
              @endif
            </td>
          </tr>
        @empty
          <tr>
            <td colspan="8" class="py-10 text-center text-sky-100/70">No users found.</td>
          </tr>
        @endforelse
        </tbody>
      </table>
    </div>
  </div>

  @if(isset($tenants))
    <div class="mt-4">{{ $tenants->links() }}</div>
  @endif
@endsection
