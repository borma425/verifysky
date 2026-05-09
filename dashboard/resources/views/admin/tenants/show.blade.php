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
    $cf = $row['cloudflare_cost'] ?? [];
    $cfSummary = $cf['summary'] ?? [];
    $cfDomains = $cf['domains'] ?? [];
    $cfOutcomes = $cf['outcomes'] ?? [];
    $cfResources = $cf['resources'] ?? [];
    $sessions = $billing['protected_sessions'] ?? null;
    $bots = $billing['bot_requests'] ?? null;
    $sessionPercent = $sessions ? min(100, max(0, (int) ($sessions['percentage'] ?? 0))) : 0;
    $botPercent = $bots ? min(100, max(0, (int) ($bots['percentage'] ?? 0))) : 0;
    $profit = (float) ($cf['profit_usd'] ?? 0);
    $statusBadge = $tenant->status === 'suspended'
      ? 'border-[#D47B78]/35 bg-[#D47B78]/15 text-[#FFE6E3]'
      : 'border-[#10B981]/25 bg-[#10B981]/10 text-[#A7F3D0]';
    $compactButton = 'vs-tuning-button vs-tuning-button-compact';
    $mutedButton = $compactButton.' vs-tuning-button-muted';
    $primaryButton = $compactButton.' vs-tuning-button-primary';
  @endphp

  <section class="vs-tuning-shell mx-auto max-w-[88rem]">
    <div class="mb-4">
      <a href="{{ route('admin.tenants.index') }}" class="{{ $mutedButton }}">Back to users</a>
    </div>

    <div class="vs-tuning-topline">
      <div class="min-w-0">
        <div class="vs-tuning-eyebrow">User Control Plane</div>
        <h1 class="vs-tuning-title truncate">{{ $tenant->name }}</h1>
        <p class="vs-tuning-subtitle">
          User #{{ $tenant->id }} / {{ $tenant->slug }}
        </p>
      </div>
      <div class="flex flex-wrap items-center justify-start gap-2 xl:justify-end">
        <span class="inline-flex min-h-[2.1rem] items-center rounded-md border px-3 text-xs font-black uppercase tracking-[0.12em] {{ $statusBadge }}">
          {{ $tenant->status }}
        </span>
        <a href="{{ route('admin.tenants.firewall.index', $tenant) }}" class="{{ $mutedButton }}">Firewall</a>
        <a href="{{ route('admin.tenants.sensitive_paths.index', $tenant) }}" class="{{ $mutedButton }}">Protected Paths</a>
        <a href="{{ route('admin.tenants.ip_farm.index', $tenant) }}" class="{{ $mutedButton }}">Blocked IPs</a>
        <form method="POST" action="{{ route('admin.tenants.force_cycle_reset', $tenant) }}">
          @csrf
          <button class="{{ $primaryButton }}" type="submit">Force Cycle Reset</button>
        </form>
      </div>
    </div>

    @unless($billingAvailable)
      <div class="vs-tuning-note mb-5">
        <strong>Billing is not ready yet.</strong>
        Run billing migrations before changing plans.
      </div>
    @endunless

    <div class="vs-tuning-grid vs-tuning-top-grid">
      <section class="vs-tuning-card vs-tuning-card-pad">
        <div class="vs-tuning-section-head">
          <div>
            <h2 class="vs-tuning-section-title">Cloudflare Cost</h2>
            <p class="vs-tuning-helper">Admin-only attribution and customer visibility control.</p>
          </div>
          @if($cf['last_synced_at'] ?? null)
            <span class="vs-tuning-badge">{{ $cf['last_synced_at']->format('Y-m-d H:i') }} UTC</span>
          @endif
        </div>

        <div class="vs-tuning-grid vs-tuning-three-grid">
          <div class="vs-tuning-panel">
            <div class="vs-tuning-helper">Estimate</div>
            <div class="mt-1 font-mono text-2xl font-black text-white">${{ number_format((float) ($cfSummary['estimated_cost_usd'] ?? 0), 2) }}</div>
          </div>
          <div class="vs-tuning-panel">
            <div class="vs-tuning-helper">Revenue</div>
            <div class="mt-1 font-mono text-2xl font-black text-white">${{ number_format((float) ($cf['monthly_revenue_usd'] ?? 0), 2) }}</div>
          </div>
          <div class="vs-tuning-panel">
            <div class="vs-tuning-helper">Profit</div>
            <div class="mt-1 font-mono text-2xl font-black {{ $profit < 0 ? 'text-[#FFE6E3]' : 'text-[#A7F3D0]' }}">
              ${{ number_format($profit, 2) }}
            </div>
            @if(($cf['margin_percentage'] ?? null) !== null)
              <div class="mt-1 font-mono text-xs text-[#ABB5CA]">{{ $cf['margin_percentage'] }}% margin</div>
            @endif
          </div>
        </div>

        <div class="mt-3 grid gap-2 sm:grid-cols-4">
          @foreach(['workers' => 'Workers', 'd1' => 'D1', 'kv' => 'KV', 'wae' => 'Telemetry'] as $key => $label)
            <div class="rounded-md bg-[#090E18] px-3 py-2">
              <div class="text-[11px] font-black uppercase tracking-[0.12em] text-[#7F8BA0]">{{ $label }}</div>
              <div class="mt-1 font-mono text-sm font-bold text-[#DEE2F0]">{{ $cfResources[$key] ?? '$0.00' }}</div>
            </div>
          @endforeach
        </div>

        <form method="POST" action="{{ route('admin.tenants.vip.update', $tenant) }}" class="mt-4">
          @csrf
          <label class="vs-tuning-toggle-row">
            <span class="min-w-0">
              <span class="block">Show cost to customer</span>
              <span class="vs-tuning-helper mt-1 block">Customer sees estimated tenant/domain cost only.</span>
            </span>
            <input type="checkbox" name="is_vip" value="1" @checked($tenant->is_vip)>
            <span class="vs-tuning-toggle" aria-hidden="true"></span>
          </label>
          <div class="vs-tuning-actions">
            <button class="{{ $primaryButton }}" type="submit">Save Visibility</button>
          </div>
        </form>
      </section>

      <section class="vs-tuning-card vs-tuning-card-pad">
        <div class="vs-tuning-section-head">
          <div>
            <h2 class="vs-tuning-section-title">Access Summary</h2>
            <p class="vs-tuning-helper">Plan, usage cycle, and active subscription.</p>
          </div>
          <span class="vs-tuning-badge">{{ $domainUsageLabel }} domains</span>
        </div>

        <div class="vs-tuning-grid vs-tuning-three-grid">
          <div class="vs-tuning-panel">
            <div class="vs-tuning-helper">Current Plan</div>
            <div class="mt-2 text-lg font-black text-white">{{ $row['effective_plan']['name'] ?? 'Free' }}</div>
            <div class="mt-2">
              <span class="vs-tuning-badge">{{ $billingTerms->sourceLabel($row['effective_plan']['source'] ?? 'baseline') }}</span>
            </div>
          </div>
          <div class="vs-tuning-panel">
            <div class="vs-tuning-helper">Plan Limit</div>
            <div class="mt-2 text-lg font-black text-white">{{ $row['baseline_plan']['name'] ?? ucfirst((string) $tenant->plan) }}</div>
            @include('partials.billing-limit-equation', ['equation' => $domainLimitEquation, 'class' => 'mt-2'])
          </div>
          <div class="vs-tuning-panel">
            <div class="vs-tuning-helper">Subscription</div>
            @if($subscription)
              <div class="mt-2 text-lg font-black text-white">{{ strtoupper($subscription['plan_key']) }}</div>
              <div class="mt-2 text-xs text-[#ABB5CA]">{{ $subscription['provider'] }} / {{ $subscription['status'] }}</div>
            @else
              <div class="mt-2 text-sm font-bold text-[#ABB5CA]">No active paid subscription</div>
            @endif
          </div>
        </div>

        <div class="mt-4 rounded-md bg-[#090E18] p-3">
          <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
            <span class="text-sm font-black capitalize text-white">{{ $billing ? str_replace('_', ' ', $billing['quota_status']) : 'No current cycle' }}</span>
            @if($billing)
              <span class="font-mono text-xs text-[#ABB5CA]">{{ $billing['current_cycle_start_at']?->format('Y-m-d') }} to {{ $billing['current_cycle_end_at']?->format('Y-m-d') }}</span>
            @endif
          </div>
          @if($billing && $sessions && $bots)
            <div class="grid gap-3 md:grid-cols-2">
              <div>
                <div class="flex items-center justify-between text-xs">
                  <span class="font-bold text-[#D7E1F5]">Sessions</span>
                  <span class="font-mono text-[#ABB5CA]">{{ $sessions['formatted_used'] }} / {{ $sessions['formatted_limit'] }}</span>
                </div>
                <div class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-[#303540]">
                  <div class="h-full rounded-full bg-[#FCB900]" style="width: {{ $sessionPercent }}%"></div>
                </div>
                @include('partials.billing-limit-equation', ['equation' => $billingTerms->billingMetricEquation($billing, $sessions, 'protected_sessions'), 'class' => 'mt-1'])
              </div>
              <div>
                <div class="flex items-center justify-between text-xs">
                  <span class="font-bold text-[#D7E1F5]">Bots</span>
                  <span class="font-mono text-[#ABB5CA]">{{ $bots['formatted_used'] }} / {{ $bots['formatted_limit'] }}</span>
                </div>
                <div class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-[#303540]">
                  <div class="h-full rounded-full {{ $botPercent >= 80 ? 'bg-[#D47B78]' : 'bg-[#FCB900]' }}" style="width: {{ $botPercent }}%"></div>
                </div>
                @include('partials.billing-limit-equation', ['equation' => $billingTerms->billingMetricEquation($billing, $bots, 'bot_fair_use'), 'class' => 'mt-1'])
              </div>
            </div>
          @endif
        </div>
      </section>
    </div>

    @if($cfDomains !== [] || $cfOutcomes !== [])
      <details class="vs-tuning-card vs-tuning-details">
        <summary class="vs-tuning-summary vs-tuning-card-pad">
          <span>
            <span class="vs-tuning-section-title block">Cost Details</span>
            <span class="vs-tuning-helper mt-1 block">Domain and outcome breakdown.</span>
          </span>
          <span class="vs-tuning-chevron">Open</span>
        </summary>
        <div class="border-t border-[#303540] p-4">
          @if($cfDomains !== [])
            <div class="overflow-x-auto rounded-md border border-[#303540]">
              <table class="es-table min-w-[760px]">
                <thead>
                <tr>
                  <th>Domain</th>
                  <th>Requests</th>
                  <th>D1 rows</th>
                  <th>KV ops</th>
                  <th>Estimated</th>
                  <th>Final</th>
                </tr>
                </thead>
                <tbody>
                @foreach($cfDomains as $domainCost)
                  <tr>
                    <td class="font-semibold text-white">{{ $domainCost['domain_name'] }}</td>
                    <td class="font-mono">{{ number_format((int) ($domainCost['requests'] ?? 0)) }}</td>
                    <td class="font-mono">{{ number_format((int) ($domainCost['d1_rows_read'] ?? 0) + (int) ($domainCost['d1_rows_written'] ?? 0)) }}</td>
                    <td class="font-mono">{{ number_format((int) ($domainCost['kv_operations'] ?? 0)) }}</td>
                    <td class="font-mono">${{ number_format((float) ($domainCost['estimated_cost_usd'] ?? 0), 4) }}</td>
                    <td class="font-mono">{{ ($domainCost['final_reconciled_cost_usd'] ?? null) === null ? 'Pending' : '$'.number_format((float) $domainCost['final_reconciled_cost_usd'], 4) }}</td>
                  </tr>
                @endforeach
                </tbody>
              </table>
            </div>
          @endif

          @if($cfOutcomes !== [])
            <h3 class="mb-3 text-sm font-black uppercase tracking-[0.12em] text-white">Outcome Breakdown</h3>
            <div class="mt-4 overflow-x-auto rounded-md border border-[#303540]">
              <table class="es-table min-w-[1040px]">
                <thead>
                <tr>
                  <th>Domain</th>
                  <th>Outcome</th>
                  <th>Requests</th>
                  <th>Estimated</th>
                  <th>Cost / 1M</th>
                  <th>D1 reads</th>
                  <th>D1 writes</th>
                  <th>KV reads</th>
                  <th>KV writes</th>
                  <th>KV write bytes</th>
                </tr>
                </thead>
                <tbody>
                @foreach($cfOutcomes as $outcomeCost)
                  <tr>
                    <td class="font-semibold text-white">{{ $outcomeCost['domain_name'] }}</td>
                    <td>{{ $outcomeCost['outcome'] }}</td>
                    <td class="font-mono">{{ number_format((int) ($outcomeCost['requests'] ?? 0)) }}</td>
                    <td class="font-mono">${{ number_format((float) ($outcomeCost['estimated_cost_usd'] ?? 0), 4) }}</td>
                    <td class="font-mono">${{ number_format((float) ($outcomeCost['cost_per_million_requests_usd'] ?? 0), 4) }}</td>
                    <td class="font-mono">{{ number_format((int) ($outcomeCost['d1_rows_read'] ?? 0)) }}</td>
                    <td class="font-mono">{{ number_format((int) ($outcomeCost['d1_rows_written'] ?? 0)) }}</td>
                    <td class="font-mono">{{ number_format((int) ($outcomeCost['kv_reads'] ?? 0)) }}</td>
                    <td class="font-mono">{{ number_format((int) ($outcomeCost['kv_writes'] ?? 0)) }}</td>
                    <td class="font-mono">{{ number_format((int) ($outcomeCost['kv_write_bytes'] ?? 0)) }}</td>
                  </tr>
                @endforeach
                </tbody>
              </table>
            </div>
          @endif
        </div>
      </details>
    @endif

    <div class="vs-tuning-grid vs-tuning-top-grid">
      <section class="vs-tuning-card vs-tuning-card-pad">
        <div class="vs-tuning-section-head">
          <div>
            <h2 class="vs-tuning-section-title">Bonus Allowance</h2>
            <p class="vs-tuning-helper">Temporary plan grants for this user.</p>
          </div>
          @if($grant)
            <form method="POST" action="{{ route('admin.tenants.manual_grants.revoke', [$tenant, $grant['id']]) }}">
              @csrf
              <button class="{{ $mutedButton }} text-[#FFE6E3]" type="submit">Revoke</button>
            </form>
          @endif
        </div>

        @if($grant)
          <div class="vs-tuning-note mb-4">
            Active {{ strtoupper($grant['granted_plan_key']) }} bonus until {{ $grant['ends_at']?->format('Y-m-d H:i') }} UTC.
          </div>
        @endif

        @if($billingAvailable)
          <form method="POST" action="{{ route('admin.tenants.manual_grants.store', $tenant) }}" class="vs-tuning-grid">
            @csrf
            <div class="vs-tuning-grid vs-tuning-two-grid">
              <label>
                <span class="vs-tuning-label">Plan</span>
                <select name="plan_key" class="vs-tuning-input">
                  @foreach($grantablePlans as $plan)
                    <option value="{{ $plan['key'] }}">{{ $plan['name'] }}</option>
                  @endforeach
                </select>
              </label>
              <label>
                <span class="vs-tuning-label">Days</span>
                <input name="duration_days" class="vs-tuning-input" type="number" min="1" max="365" value="14">
              </label>
            </div>
            <label>
              <span class="vs-tuning-label">Reason</span>
              <input name="reason" class="vs-tuning-input" placeholder="Reason">
            </label>
            <div class="vs-tuning-actions">
              <button class="{{ $primaryButton }}" type="submit">Activate Bonus</button>
            </div>
          </form>
        @endif
      </section>

      <section class="vs-tuning-card vs-tuning-card-pad">
        <div class="vs-tuning-section-head">
          <div>
            <h2 class="vs-tuning-section-title">Members</h2>
            <p class="vs-tuning-helper">{{ $tenant->memberships->count() }} member(s) on this tenant.</p>
          </div>
        </div>

        <div class="grid gap-2">
          @forelse($tenant->memberships as $membership)
            <div class="flex items-center justify-between gap-3 rounded-md bg-[#090E18] px-3 py-2">
              <div class="min-w-0">
                <div class="truncate text-sm font-bold text-white">{{ $membership->user?->name ?? 'Unknown user' }}</div>
                <div class="truncate text-xs text-[#ABB5CA]">{{ $membership->user?->email }}</div>
              </div>
              <span class="vs-tuning-badge">{{ $membership->role }}</span>
            </div>
          @empty
            <div class="rounded-md bg-[#090E18] px-3 py-8 text-center text-sm text-[#ABB5CA]">No members.</div>
          @endforelse
        </div>
      </section>
    </div>

    @if($domainAssetSummaries !== [])
      <details class="vs-tuning-card vs-tuning-details">
        <summary class="vs-tuning-summary vs-tuning-card-pad">
          <span>
            <span class="vs-tuning-section-title block">Domain Asset History</span>
            <span class="vs-tuning-helper mt-1 block">Trial and quarantine source of truth.</span>
          </span>
          <span class="vs-tuning-chevron">Open</span>
        </summary>
        <div class="grid gap-3 border-t border-[#303540] p-4 xl:grid-cols-2">
          @foreach($domainAssetSummaries as $asset)
            @php
              $isShared = ($asset['asset_type'] ?? '') === \App\Models\DomainAssetHistory::TYPE_SHARED_HOSTNAME;
              $quarantinedUntil = $asset['quarantined_until'] ?? null;
            @endphp
            <div class="vs-tuning-panel">
              <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                  <div class="truncate font-bold text-white">{{ $asset['hostname'] }}</div>
                  <div class="mt-1 truncate font-mono text-xs text-[#ABB5CA]">{{ $asset['asset_key'] }}</div>
                </div>
                <span class="vs-tuning-badge">{{ $isShared ? 'Shared hostname' : 'Registrable domain' }}</span>
              </div>
              <div class="mt-3 grid gap-2 text-sm text-[#ABB5CA] md:grid-cols-2">
                <div>Trial used: <span class="font-semibold text-white">{{ $asset['trial_used'] ? 'Yes' : 'No' }}</span></div>
                <div>Quarantine: <span class="font-semibold text-white">{{ $quarantinedUntil ? $quarantinedUntil->format('Y-m-d H:i').' UTC' : 'None' }}</span></div>
              </div>
            </div>
          @endforeach
        </div>
      </details>
    @endif

    <section class="vs-tuning-card vs-tuning-card-pad">
      <div class="vs-tuning-section-head">
        <div>
          <h2 class="vs-tuning-section-title">Domains</h2>
          <p class="vs-tuning-helper">Manage routing, cache, protection settings, and firewall rules for this user.</p>
        </div>
        <span class="vs-tuning-badge">
          {{ $domainUsageLabel }} used
          @if($domainRemaining !== null)
            / {{ $domainRemaining }} remaining
          @endif
        </span>
      </div>

      <form method="POST" action="{{ route('admin.tenants.domains.store', $tenant) }}" class="vs-tuning-grid">
        @csrf
        <div class="vs-tuning-grid vs-tuning-three-grid">
          <label>
            <span class="vs-tuning-label">Domain</span>
            <input name="domain_name" class="vs-tuning-input" value="{{ old('domain_name') }}" placeholder="example.com" @disabled(! $canAddDomain)>
          </label>
          <label>
            <span class="vs-tuning-label">Server IP or domain</span>
            <input name="origin_server" class="vs-tuning-input" value="{{ old('origin_server') }}" placeholder="192.0.2.10" @disabled(! $canAddDomain)>
          </label>
          <label>
            <span class="vs-tuning-label">Protection level</span>
            <select name="security_mode" class="vs-tuning-input" @disabled(! $canAddDomain)>
              <option value="balanced" @selected(old('security_mode', 'balanced') === 'balanced')>Balanced</option>
              <option value="monitor" @selected(old('security_mode') === 'monitor')>Monitor only</option>
              <option value="aggressive" @selected(old('security_mode') === 'aggressive')>Aggressive</option>
            </select>
          </label>
        </div>

        <div class="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
          @if($canAddDomain)
            <p class="vs-tuning-helper">Adding a domain starts VerifySky protection setup for this user.</p>
            <button class="{{ $primaryButton }}" type="submit">Add Domain</button>
          @else
            <p class="text-sm text-[#FFDC9C]">This user has reached the domain limit. Upgrade the plan or add extra space first.</p>
            <button class="{{ $primaryButton }} opacity-60" type="button" disabled>Add Domain</button>
          @endif
        </div>
      </form>

      <div class="mt-5 overflow-x-auto rounded-md border border-[#303540]">
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
                <div class="flex flex-wrap gap-1.5">
                  <a href="{{ route('admin.tenants.domains.show', [$tenant, $domain->hostname]) }}" class="{{ $mutedButton }}">Manage Domain</a>
                  <a href="{{ route('admin.tenants.domains.firewall.index', [$tenant, $domain->hostname]) }}" class="{{ $mutedButton }}">Firewall</a>
                  <form method="POST" action="{{ route('admin.tenants.domains.destroy_group', [$tenant, $domain->hostname]) }}" onsubmit="return confirm('Delete this domain from this user? This cannot be undone.');">
                    @csrf
                    @method('DELETE')
                    <button class="{{ $compactButton }} bg-[#D47B78] text-white" type="submit">Delete</button>
                  </form>
                </div>
              </td>
            </tr>
          @empty
            <tr>
              <td colspan="5" class="py-8 text-center text-[#ABB5CA]">No domains assigned.</td>
            </tr>
          @endforelse
          </tbody>
        </table>
      </div>
    </section>

    <section class="vs-tuning-card vs-tuning-card-pad border-[#D47B78]/35">
      <div class="vs-tuning-section-head">
        <div>
          <h2 class="vs-tuning-section-title text-[#FFE6E3]">User Controls</h2>
          <p class="vs-tuning-helper">Suspend access, resume access, or permanently delete this user.</p>
        </div>
        <span class="vs-tuning-badge">Current status: {{ $tenant->status }}</span>
      </div>
      <div class="flex flex-col gap-2 md:flex-row md:justify-end">
        @if($tenant->status === 'suspended')
          <form method="POST" action="{{ route('admin.tenants.account.resume', $tenant) }}">
            @csrf
            <button class="{{ $primaryButton }}" type="submit">Resume User</button>
          </form>
        @else
          <form method="POST" action="{{ route('admin.tenants.account.suspend', $tenant) }}">
            @csrf
            <button class="{{ $primaryButton }}" type="submit">Suspend User</button>
          </form>
        @endif
        <form method="POST" action="{{ route('admin.tenants.account.delete', $tenant) }}" onsubmit="return confirm('Permanently delete this user and all assigned domains? This cannot be undone.');">
          @csrf
          @method('DELETE')
          <button class="{{ $compactButton }} bg-[#D47B78] text-white" type="submit">Delete User</button>
        </form>
      </div>
    </section>
  </section>
@endsection
