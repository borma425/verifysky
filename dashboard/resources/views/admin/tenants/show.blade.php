@extends('layouts.admin')

@section('content')
  @php
    $billingTerms = app(\App\ViewData\BillingTerminologyViewData::class);
    $billing = $row['billing'];
    $grant = $row['active_grant'];
    $subscription = $row['active_subscription'];
    $domainLimit = $domainsUsage['limit'] ?? null;
    $domainUsed = (int) ($domainsUsage['used'] ?? $tenant->domains->count());
    $domainRemaining = $domainsUsage['remaining'] ?? null;
    $canAddDomain = (bool) ($domainsUsage['can_add'] ?? true);
    $domainUsageLabel = $domainLimit === null ? $domainUsed.' / Unlimited' : $domainUsed.' / '.$domainLimit;
    $domainLimitEquation = $billingTerms->domainEquation($domainsUsage);
    $domainAssetSummaries = $domainAssetSummaries ?? [];
  @endphp

  <div class="mb-6 flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
    <div>
      <a href="{{ route('admin.tenants.index') }}" class="text-sm font-semibold text-cyan-200 hover:text-cyan-100">Back to users</a>
      <h1 class="es-title mt-2">{{ $tenant->name }}</h1>
      <p class="es-subtitle mt-2">User #{{ $tenant->id }} / {{ $tenant->slug }} / {{ $tenant->status }}</p>
    </div>
    <div class="flex flex-wrap items-center gap-3">
      <a href="{{ route('admin.tenants.firewall.index', $tenant) }}" class="es-btn es-btn-secondary">Firewall</a>
      <a href="{{ route('admin.tenants.sensitive_paths.index', $tenant) }}" class="es-btn es-btn-secondary">Protected Paths</a>
      <a href="{{ route('admin.tenants.ip_farm.index', $tenant) }}" class="es-btn es-btn-secondary">Blocked IPs</a>
      <form method="POST" action="{{ route('admin.tenants.force_cycle_reset', $tenant) }}">
        @csrf
        <button class="es-btn" type="submit">Force Cycle Reset</button>
      </form>
    </div>
  </div>

  @unless($billingAvailable)
    <div class="mb-4 rounded-lg border border-amber-400/35 bg-amber-500/15 px-4 py-3 text-sm text-amber-100">
      Billing is not ready yet. Run billing migrations before changing plans.
    </div>
  @endunless

  <div class="grid gap-4 xl:grid-cols-3">
    <div class="es-card p-5">
      <div class="text-xs font-bold uppercase tracking-[0.18em] text-[#7F8BA0]">Plan</div>
      <div class="mt-3 text-xl font-bold text-white">{{ $row['effective_plan']['name'] ?? 'Starter' }}</div>
      <div class="mt-1 text-sm text-sky-100/70">Current access: {{ $billingTerms->sourceLabel($row['effective_plan']['source'] ?? 'baseline') }}</div>
      <div class="mt-1 text-sm text-sky-100/70">Plan Limit: {{ $row['baseline_plan']['name'] ?? ucfirst((string) $tenant->plan) }}</div>
      <div class="mt-3 text-sm text-sky-100/80">Domains: {{ $domainUsageLabel }}</div>
      @include('partials.billing-limit-equation', ['equation' => $domainLimitEquation])
    </div>
    <div class="es-card p-5">
      <div class="text-xs font-bold uppercase tracking-[0.18em] text-[#7F8BA0]">Billing Cycle</div>
      @if($billing)
        <div class="mt-3 text-xl font-bold text-white">{{ str_replace('_', ' ', $billing['quota_status']) }}</div>
        <div class="mt-1 text-sm text-sky-100/70">{{ $billing['current_cycle_start_at']?->format('Y-m-d') }} to {{ $billing['current_cycle_end_at']?->format('Y-m-d') }}</div>
        <div class="mt-3 text-sm text-sky-100/80">Sessions: {{ $billing['protected_sessions']['formatted_used'] }} / {{ $billing['protected_sessions']['formatted_limit'] }}</div>
        @include('partials.billing-limit-equation', ['equation' => $billingTerms->billingMetricEquation($billing, $billing['protected_sessions'], 'protected_sessions'), 'class' => 'mt-1'])
        <div class="text-sm text-sky-100/80">Bots: {{ $billing['bot_requests']['formatted_used'] }} / {{ $billing['bot_requests']['formatted_limit'] }}</div>
        @include('partials.billing-limit-equation', ['equation' => $billingTerms->billingMetricEquation($billing, $billing['bot_requests'], 'bot_fair_use'), 'class' => 'mt-1'])
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

  @if($domainAssetSummaries !== [])
    <div class="es-card mt-5 p-5">
      <div class="mb-4 flex flex-col gap-1 md:flex-row md:items-end md:justify-between">
        <div>
          <h2 class="text-lg font-bold text-white">Domain Asset History</h2>
          <p class="mt-1 text-sm text-sky-100/65">Trial and quarantine source of truth for this user's domains.</p>
        </div>
        <div class="text-xs font-bold uppercase tracking-[0.16em] text-sky-100/50">Support view</div>
      </div>
      <div class="grid gap-3 xl:grid-cols-2">
        @foreach($domainAssetSummaries as $asset)
          @php
            $isShared = ($asset['asset_type'] ?? '') === \App\Models\DomainAssetHistory::TYPE_SHARED_HOSTNAME;
            $quarantinedUntil = $asset['quarantined_until'] ?? null;
          @endphp
          <div class="rounded-lg border border-white/10 bg-white/[0.03] p-4">
            <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
              <div class="min-w-0">
                <div class="truncate font-semibold text-white">{{ $asset['hostname'] }}</div>
                <div class="mt-1 truncate font-mono text-xs text-sky-100/65">{{ $asset['asset_key'] }}</div>
              </div>
              <span class="shrink-0 rounded-full border {{ $isShared ? 'border-amber-300/30 bg-amber-400/10 text-amber-100' : 'border-cyan-300/25 bg-cyan-400/10 text-cyan-100' }} px-2 py-1 text-[11px] font-bold uppercase tracking-[0.12em]">
                {{ $isShared ? 'Shared hostname' : 'Registrable domain' }}
              </span>
            </div>
            <div class="mt-4 grid gap-2 text-sm text-sky-100/75 md:grid-cols-2">
              <div>Trial used: <span class="font-semibold text-white">{{ $asset['trial_used'] ? 'Yes' : 'No' }}</span></div>
              <div>
                Quarantine:
                <span class="font-semibold text-white">
                  {{ $quarantinedUntil ? $quarantinedUntil->format('Y-m-d H:i').' UTC' : 'None' }}
                </span>
              </div>
            </div>
          </div>
        @endforeach
      </div>
    </div>
  @endif

  <div class="mt-5 grid gap-4 xl:grid-cols-2">
    <div class="es-card p-5">
      <div class="mb-4 flex items-center justify-between">
        <h2 class="text-lg font-bold text-white">Bonus Allowance</h2>
        @if($grant)
          <form method="POST" action="{{ route('admin.tenants.manual_grants.revoke', [$tenant, $grant['id']]) }}">
            @csrf
            <button class="text-sm font-semibold text-rose-200 hover:text-rose-100" type="submit">Revoke Active Bonus</button>
          </form>
        @endif
      </div>
      @if($grant)
        <div class="mb-4 rounded-lg border border-cyan-300/25 bg-cyan-400/10 px-3 py-2 text-sm text-cyan-100">
          Active {{ strtoupper($grant['granted_plan_key']) }} bonus until {{ $grant['ends_at']?->format('Y-m-d H:i') }} UTC.
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
          <button class="es-btn" type="submit">Activate Bonus Allowance</button>
        </form>
      @endif
    </div>

    <div class="es-card p-5">
      <h2 class="mb-4 text-lg font-bold text-white">Members</h2>
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
          <div class="text-sm text-sky-100/70">No members.</div>
        @endforelse
      </div>
    </div>
  </div>

  <div class="es-card mt-5 p-5">
    <div class="mb-4 flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
      <div>
        <h2 class="text-lg font-bold text-white">User Controls</h2>
        <p class="mt-1 text-sm text-sky-100/65">Suspend access, resume access, or permanently delete this user.</p>
      </div>
      <div class="text-sm text-sky-100/70">Current status: {{ $tenant->status }}</div>
    </div>
    <div class="grid gap-3 xl:grid-cols-3">
      @if($tenant->status === 'suspended')
        <form method="POST" action="{{ route('admin.tenants.account.resume', $tenant) }}">
          @csrf
          <button class="es-btn w-full" type="submit">Resume User</button>
        </form>
      @else
        <form method="POST" action="{{ route('admin.tenants.account.suspend', $tenant) }}">
          @csrf
          <button class="es-btn es-btn-warning w-full" type="submit">Suspend User</button>
        </form>
      @endif
      <form method="POST" action="{{ route('admin.tenants.account.delete', $tenant) }}" class="xl:col-span-2">
        @csrf
        @method('DELETE')
        <div class="flex flex-col gap-2 md:flex-row">
          <input class="es-input" name="confirm_tenant" placeholder="Type user slug to delete: {{ $tenant->slug }}">
          <button class="es-btn es-btn-danger whitespace-nowrap" type="submit">Delete User</button>
        </div>
      </form>
    </div>
  </div>

  <div class="es-card mt-5 p-0">
    <div class="border-b border-white/10 p-5">
      <div class="flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">
        <div>
          <h2 class="text-lg font-bold text-white">Domains</h2>
          <p class="mt-1 text-sm text-sky-100/65">Open a domain to manage server routing, cache, settings, and firewall rules for this user.</p>
        </div>
        <div class="rounded-lg border border-white/10 bg-white/[0.03] px-3 py-2 text-sm text-sky-100/80">
          {{ $domainUsageLabel }} domains used
          @if($domainRemaining !== null)
            <span class="text-sky-100/55">/ {{ $domainRemaining }} remaining</span>
          @endif
        </div>
      </div>
      <form method="POST" action="{{ route('admin.tenants.domains.store', $tenant) }}" class="mt-5 grid gap-3 xl:grid-cols-5">
        @csrf
        <label class="xl:col-span-2">
          <span class="mb-1 block text-sm text-sky-100">Domain</span>
          <input name="domain_name" class="es-input" value="{{ old('domain_name') }}" placeholder="example.com" @disabled(! $canAddDomain)>
        </label>
        <label class="xl:col-span-2">
          <span class="mb-1 block text-sm text-sky-100">Server IP or domain</span>
          <input name="origin_server" class="es-input" value="{{ old('origin_server') }}" placeholder="192.0.2.10" @disabled(! $canAddDomain)>
        </label>
        <label>
          <span class="mb-1 block text-sm text-sky-100">Protection level</span>
          <select name="security_mode" class="es-input" @disabled(! $canAddDomain)>
            <option value="balanced" @selected(old('security_mode', 'balanced') === 'balanced')>Balanced</option>
            <option value="monitor" @selected(old('security_mode') === 'monitor')>Monitor only</option>
            <option value="aggressive" @selected(old('security_mode') === 'aggressive')>Aggressive</option>
          </select>
        </label>
        <div class="xl:col-span-5 flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
          @if($canAddDomain)
            <div class="text-sm text-sky-100/65">Adding a domain starts VerifySky protection setup for this user.</div>
            <button class="es-btn" type="submit">Add Domain</button>
          @else
            <div class="text-sm text-amber-100">This user has reached the domain limit. Upgrade the plan or add extra space first.</div>
            <button class="es-btn opacity-60" type="button" disabled>Add Domain</button>
          @endif
        </div>
      </form>
    </div>
    <div class="overflow-x-auto">
      <table class="es-table min-w-[900px]">
        <thead>
        <tr>
          <th>Domain</th>
          <th>Status</th>
          <th>Security</th>
          <th>Server</th>
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
