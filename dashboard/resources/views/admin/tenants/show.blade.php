@extends('layouts.admin')

@section('content')
  @php
    $billing = $row['billing'];
    $grant = $row['active_grant'];
    $subscription = $row['active_subscription'];
  @endphp

  <div class="mb-6 flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
    <div>
      <a href="{{ route('admin.tenants.index') }}" class="text-sm font-semibold text-cyan-200 hover:text-cyan-100">Back to tenants</a>
      <h1 class="es-title mt-2">{{ $tenant->name }}</h1>
      <p class="es-subtitle mt-2">Tenant #{{ $tenant->id }} / {{ $tenant->slug }} / {{ $tenant->status }}</p>
    </div>
    <div class="flex flex-wrap items-center gap-3">
      <a href="{{ route('admin.tenants.firewall.index', $tenant) }}" class="es-btn es-btn-secondary">Global Firewall</a>
      <a href="{{ route('admin.tenants.sensitive_paths.index', $tenant) }}" class="es-btn es-btn-secondary">Sensitive Paths</a>
      <a href="{{ route('admin.tenants.ip_farm.index', $tenant) }}" class="es-btn es-btn-secondary">IP Farm</a>
      <form method="POST" action="{{ route('admin.tenants.force_cycle_reset', $tenant) }}">
        @csrf
        <button class="es-btn" type="submit">Force Cycle Reset</button>
      </form>
    </div>
  </div>

  @unless($billingAvailable)
    <div class="mb-4 rounded-lg border border-amber-400/35 bg-amber-500/15 px-4 py-3 text-sm text-amber-100">
      Billing tables are not available yet. Run billing migrations before plan operations.
    </div>
  @endunless

  <div class="grid gap-4 xl:grid-cols-3">
    <div class="es-card p-5">
      <div class="text-xs font-bold uppercase tracking-[0.18em] text-[#7F8BA0]">Plan</div>
      <div class="mt-3 text-xl font-bold text-white">{{ $row['effective_plan']['name'] ?? 'Starter' }}</div>
      <div class="mt-1 text-sm text-sky-100/70">Source: {{ str_replace('_', ' ', $row['effective_plan']['source'] ?? 'baseline') }}</div>
      <div class="mt-1 text-sm text-sky-100/70">Baseline: {{ $row['baseline_plan']['name'] ?? ucfirst((string) $tenant->plan) }}</div>
    </div>
    <div class="es-card p-5">
      <div class="text-xs font-bold uppercase tracking-[0.18em] text-[#7F8BA0]">Billing Cycle</div>
      @if($billing)
        <div class="mt-3 text-xl font-bold text-white">{{ str_replace('_', ' ', $billing['quota_status']) }}</div>
        <div class="mt-1 text-sm text-sky-100/70">{{ $billing['current_cycle_start_at']?->format('Y-m-d') }} to {{ $billing['current_cycle_end_at']?->format('Y-m-d') }}</div>
        <div class="mt-3 text-sm text-sky-100/80">Sessions: {{ $billing['protected_sessions']['formatted_used'] }} / {{ $billing['protected_sessions']['formatted_limit'] }}</div>
        <div class="text-sm text-sky-100/80">Bots: {{ $billing['bot_requests']['formatted_used'] }} / {{ $billing['bot_requests']['formatted_limit'] }}</div>
      @else
        <div class="mt-3 text-sm text-amber-100">No current cycle loaded.</div>
      @endif
    </div>
    <div class="es-card p-5">
      <div class="text-xs font-bold uppercase tracking-[0.18em] text-[#7F8BA0]">Active Subscription</div>
      @if($subscription)
        <div class="mt-3 text-xl font-bold text-white">{{ strtoupper($subscription['plan_key']) }}</div>
        <div class="mt-1 text-sm text-sky-100/70">{{ $subscription['provider'] }} / {{ $subscription['status'] }}</div>
        <div class="mt-1 text-xs text-sky-100/60">{{ $subscription['provider_subscription_id'] }}</div>
      @else
        <div class="mt-3 text-sm text-sky-100/70">No active paid subscription.</div>
      @endif
    </div>
  </div>

  <div class="mt-5 grid gap-4 xl:grid-cols-2">
    <div class="es-card p-5">
      <div class="mb-4 flex items-center justify-between">
        <h2 class="text-lg font-bold text-white">Manual Grants</h2>
        @if($grant)
          <form method="POST" action="{{ route('admin.tenants.manual_grants.revoke', [$tenant, $grant['id']]) }}">
            @csrf
            <button class="text-sm font-semibold text-rose-200 hover:text-rose-100" type="submit">Revoke Active Grant</button>
          </form>
        @endif
      </div>
      @if($grant)
        <div class="mb-4 rounded-lg border border-cyan-300/25 bg-cyan-400/10 px-3 py-2 text-sm text-cyan-100">
          Active {{ strtoupper($grant['granted_plan_key']) }} grant until {{ $grant['ends_at']?->format('Y-m-d H:i') }} UTC.
        </div>
      @endif
      @if($billingAvailable)
        <form method="POST" action="{{ route('admin.tenants.manual_grants.store', $tenant) }}" class="grid gap-3">
          @csrf
          <div class="grid gap-3 md:grid-cols-2">
            <select name="plan_key" class="es-input">
              @foreach($grantablePlans as $plan)
                <option value="{{ $plan['key'] }}">{{ $plan['name'] }}</option>
              @endforeach
            </select>
            <input name="duration_days" class="es-input" type="number" min="1" max="365" value="14">
          </div>
          <input name="reason" class="es-input" placeholder="Reason">
          <button class="es-btn" type="submit">Activate Manual Grant</button>
        </form>
      @endif
    </div>

    <div class="es-card p-5">
      <h2 class="mb-4 text-lg font-bold text-white">Memberships</h2>
      <div class="space-y-2">
        @forelse($tenant->memberships as $membership)
          <div class="flex items-center justify-between rounded-lg border border-white/10 px-3 py-2">
            <div>
              <div class="font-semibold text-white">{{ $membership->user?->name ?? 'Unknown user' }}</div>
              <div class="text-xs text-sky-100/60">{{ $membership->user?->email }}</div>
            </div>
            <span class="text-xs font-bold uppercase tracking-[0.14em] text-cyan-200">{{ $membership->role }}</span>
          </div>
        @empty
          <div class="text-sm text-sky-100/70">No memberships.</div>
        @endforelse
      </div>
    </div>
  </div>

  <div class="es-card mt-5 p-5">
    <div class="mb-4 flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
      <div>
        <h2 class="text-lg font-bold text-white">Account Controls</h2>
        <p class="mt-1 text-sm text-sky-100/65">Suspend access, resume access, or permanently delete this tenant.</p>
      </div>
      <div class="text-sm text-sky-100/70">Current status: {{ $tenant->status }}</div>
    </div>
    <div class="grid gap-3 xl:grid-cols-3">
      @if($tenant->status === 'suspended')
        <form method="POST" action="{{ route('admin.tenants.account.resume', $tenant) }}">
          @csrf
          <button class="es-btn w-full" type="submit">Resume Account</button>
        </form>
      @else
        <form method="POST" action="{{ route('admin.tenants.account.suspend', $tenant) }}">
          @csrf
          <button class="es-btn es-btn-warning w-full" type="submit">Suspend Account</button>
        </form>
      @endif
      <form method="POST" action="{{ route('admin.tenants.account.delete', $tenant) }}" class="xl:col-span-2">
        @csrf
        @method('DELETE')
        <div class="flex flex-col gap-2 md:flex-row">
          <input class="es-input" name="confirm_tenant" placeholder="Type tenant slug to delete: {{ $tenant->slug }}">
          <button class="es-btn es-btn-danger whitespace-nowrap" type="submit">Delete Account</button>
        </div>
      </form>
    </div>
  </div>

  <div class="es-card mt-5 p-0">
    <div class="border-b border-white/10 p-5">
      <h2 class="text-lg font-bold text-white">Domains</h2>
      <p class="mt-1 text-sm text-sky-100/65">Open a domain to manage routing, cache purge, tuning, and firewall rules through admin-scoped actions.</p>
    </div>
    <div class="overflow-x-auto">
      <table class="es-table min-w-[900px]">
        <thead>
        <tr>
          <th>Hostname</th>
          <th>Status</th>
          <th>Security</th>
          <th>Origin</th>
          <th>Actions</th>
        </tr>
        </thead>
        <tbody>
        @forelse($tenant->domains as $domain)
          <tr>
            <td class="font-semibold text-white">{{ $domain->hostname }}</td>
            <td>{{ $domain->hostname_status ?: 'pending' }} / {{ $domain->ssl_status ?: 'pending' }}</td>
            <td>{{ $domain->security_mode ?: 'balanced' }}{{ $domain->force_captcha ? ' / force captcha' : '' }}</td>
            <td>{{ $domain->origin_server ?: 'not set' }}</td>
            <td>
              <div class="flex flex-wrap gap-2">
                <a href="{{ route('admin.tenants.domains.show', [$tenant, $domain->hostname]) }}" class="es-btn es-btn-secondary px-3 py-2 text-xs">Manage Domain</a>
                <a href="{{ route('admin.tenants.domains.firewall.index', [$tenant, $domain->hostname]) }}" class="es-btn es-btn-secondary px-3 py-2 text-xs">Firewall</a>
              </div>
            </td>
          </tr>
        @empty
          <tr>
            <td colspan="5" class="py-8 text-center text-sky-100/70">No domains assigned.</td>
          </tr>
        @endforelse
        </tbody>
      </table>
    </div>
  </div>
@endsection
