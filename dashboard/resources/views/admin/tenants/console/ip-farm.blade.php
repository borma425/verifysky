@extends('layouts.admin')

@section('content')
  <div class="mb-6 flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
    <div>
      <a href="{{ route('admin.tenants.show', $tenant) }}" class="text-sm font-semibold text-cyan-200 hover:text-cyan-100">Back to {{ $tenant->name }}</a>
      <h1 class="es-title mt-2">Global IP Farm</h1>
      <p class="es-subtitle mt-2">Permanent IP/CIDR bans scoped to all domains (Global) or one domain. Admin actions bypass plan limits.</p>
    </div>
    <div class="flex flex-wrap gap-2">
      <a href="{{ route('admin.tenants.firewall.index', $tenant) }}" class="es-btn es-btn-secondary">Firewall</a>
      <a href="{{ route('admin.tenants.sensitive_paths.index', $tenant) }}" class="es-btn es-btn-secondary">Sensitive Paths</a>
      <a href="{{ route('admin.tenants.customer.logs.index', $tenant) }}" class="es-btn es-btn-secondary">Logs</a>
    </div>
  </div>

  @if(!empty($loadErrors))
    <div class="mb-4 rounded-xl border border-amber-400/35 bg-amber-500/15 px-4 py-3 text-sm text-amber-100">
      @foreach($loadErrors as $msg)
        <div>{{ $msg }}</div>
      @endforeach
    </div>
  @endif

  <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
    <div class="es-card p-4">
      <div class="text-2xl font-extrabold text-rose-100">{{ number_format($stats['totalIps'] ?? 0) }}</div>
      <div class="text-xs text-rose-300/70">Banned IPs / CIDRs</div>
    </div>
    <div class="es-card p-4">
      <div class="text-2xl font-extrabold text-sky-100">{{ $stats['totalRules'] ?? count($farmRules) }}</div>
      <div class="text-xs text-sky-300/70">Farm Rules</div>
    </div>
    <div class="es-card p-4">
      <div class="text-lg font-bold text-emerald-100">{{ $stats['lastUpdated'] ?? 'Never' }}</div>
      <div class="text-xs text-emerald-300/70">Last Updated</div>
    </div>
  </div>

  <div class="grid gap-5 xl:grid-cols-[420px_1fr]">
    <div class="space-y-5">
      <div class="es-card p-5">
        <h2 class="mb-4 text-lg font-bold text-white">Create Global Farm</h2>
        <form method="POST" action="{{ route('admin.tenants.ip_farm.store', $tenant) }}" class="space-y-3">
          @csrf
          <div>
            <label class="mb-1 block text-sm text-sky-100">Scope</label>
            <select class="es-input text-sm" name="scope">
              <option value="tenant">All Domains (Global)</option>
              <option value="domain">Specific domain</option>
            </select>
          </div>
          <div>
            <label class="mb-1 block text-sm text-sky-100">Domain</label>
            <select class="es-input text-sm" name="domain_name">
              @foreach($domainRecords as $domain)
                <option value="{{ $domain->hostname }}">{{ $domain->hostname }}</option>
              @endforeach
            </select>
          </div>
          <input class="es-input text-sm" name="description" value="{{ old('description', 'Admin Manual Farm') }}" placeholder="Farm name">
          <textarea class="es-input min-h-40 font-mono text-xs" name="ips" placeholder="203.0.113.10&#10;198.51.100.0/24" required>{{ old('ips') }}</textarea>
          <label class="inline-flex items-center gap-2 text-sm text-sky-100/70">
            <input type="checkbox" name="paused" value="1" class="rounded border-white/20 bg-slate-900/70">
            Create as paused
          </label>
          <button class="es-btn w-full" type="submit">Create Farm</button>
        </form>
      </div>

      <div class="es-card p-5">
        <h2 class="mb-4 text-lg font-bold text-white">Remove Targets Across Global Scope</h2>
        <form method="POST" action="{{ route('admin.tenants.ip_farm.remove', $tenant) }}" class="space-y-3">
          @csrf
          <textarea class="es-input min-h-28 font-mono text-xs" name="ips" placeholder="203.0.113.10&#10;198.51.100.4" required></textarea>
          <button class="es-btn es-btn-danger w-full" type="submit">Remove From All Farms</button>
        </form>
      </div>

      <form method="POST" action="{{ route('admin.tenants.ip_farm.bulk_destroy', $tenant) }}" id="adminBulkFarmDelete">
        @csrf
        @method('DELETE')
      </form>
      <button class="es-btn es-btn-danger w-full" type="submit" form="adminBulkFarmDelete">Delete Selected Farms</button>
    </div>

    <div class="space-y-4">
      @forelse($farmRules as $rule)
        @php
          $scope = ($rule['scope'] ?? '') === 'tenant' || ($rule['domain_name'] ?? '') === 'global' ? 'tenant' : 'domain';
        @endphp
        <div class="es-card p-0">
          <div class="flex flex-wrap items-center justify-between gap-3 border-b border-white/10 px-4 py-3">
            <div class="flex items-center gap-3">
              <input type="checkbox" name="rule_ids[]" value="{{ $rule['id'] }}" form="adminBulkFarmDelete" class="rounded border-white/20 bg-slate-900/70">
              <div>
                <h3 class="font-bold text-rose-100">{{ str_replace('[IP-FARM] ', '', $rule['description']) }}</h3>
                <div class="mt-0.5 text-[11px] text-sky-100/60">
                  #{{ $rule['id'] }} · {{ $scope === 'tenant' ? 'All Domains (Global)' : $rule['domain_name'] }} · {{ $rule['paused'] ? 'Paused' : 'Active' }}
                </div>
              </div>
            </div>
            <div class="flex flex-wrap items-center gap-2">
              <span class="es-chip text-xs">{{ number_format($rule['ip_count']) }} targets</span>
              <form method="POST" action="{{ route('admin.tenants.ip_farm.toggle', [$tenant, $rule['id']]) }}">
                @csrf
                <input type="hidden" name="paused" value="{{ $rule['paused'] ? 0 : 1 }}">
                <button class="es-btn es-btn-secondary px-3 py-1.5 text-xs" type="submit">{{ $rule['paused'] ? 'Enable' : 'Pause' }}</button>
              </form>
              <form method="POST" action="{{ route('admin.tenants.ip_farm.destroy', [$tenant, $rule['id']]) }}" onsubmit="return confirm('Delete this IP Farm rule?')">
                @csrf
                @method('DELETE')
                <button class="es-btn es-btn-danger px-3 py-1.5 text-xs" type="submit">Delete Farm</button>
              </form>
            </div>
          </div>

          <div class="grid gap-4 p-4 lg:grid-cols-2">
            <form method="POST" action="{{ route('admin.tenants.ip_farm.update', [$tenant, $rule['id']]) }}" class="space-y-3">
              @csrf
              @method('PUT')
              <div class="grid gap-3 md:grid-cols-2">
                <div>
                  <label class="mb-1 block text-sm text-sky-100">Scope</label>
                  <select class="es-input text-sm" name="scope">
                    <option value="tenant" @selected($scope === 'tenant')>All Domains (Global)</option>
                    <option value="domain" @selected($scope === 'domain')>Specific domain</option>
                  </select>
                </div>
                <div>
                  <label class="mb-1 block text-sm text-sky-100">Domain</label>
                  <select class="es-input text-sm" name="domain_name">
                    @foreach($domainRecords as $domain)
                      <option value="{{ $domain->hostname }}" @selected(($rule['domain_name'] ?? '') === $domain->hostname)>{{ $domain->hostname }}</option>
                    @endforeach
                  </select>
                </div>
              </div>
              <input class="es-input text-sm" name="description" value="{{ str_replace('[IP-FARM] ', '', preg_replace('/\s*\(\d+\s+IPs\)\s*$/', '', $rule['description'])) }}">
              <textarea class="es-input min-h-40 font-mono text-xs" name="ips" required>{{ $rule['ips_text'] }}</textarea>
              <label class="inline-flex items-center gap-2 text-sm text-sky-100/70">
                <input type="checkbox" name="paused" value="1" class="rounded border-white/20 bg-slate-900/70" @checked($rule['paused'])>
                Paused
              </label>
              <button class="es-btn w-full" type="submit">Save Farm</button>
            </form>

            <div class="space-y-3">
              <form method="POST" action="{{ route('admin.tenants.ip_farm.append', [$tenant, $rule['id']]) }}" class="space-y-2">
                @csrf
                <label class="block text-sm text-sky-100">Append IPs / CIDRs</label>
                <textarea class="es-input min-h-24 font-mono text-xs" name="ips" placeholder="203.0.113.25&#10;2001:db8::/64" required></textarea>
                <button class="es-btn es-btn-secondary w-full" type="submit">Append To Farm</button>
              </form>
              <form method="POST" action="{{ route('admin.tenants.ip_farm.remove_ips', [$tenant, $rule['id']]) }}" class="space-y-2">
                @csrf
                <label class="block text-sm text-sky-100">Remove IPs / CIDRs From This Farm</label>
                <textarea class="es-input min-h-24 font-mono text-xs" name="ips" placeholder="203.0.113.10" required></textarea>
                <button class="es-btn es-btn-danger w-full" type="submit">Remove Targets</button>
              </form>
              <div class="max-h-36 overflow-auto rounded-lg border border-white/10 bg-slate-950/40 p-3">
                <div class="flex flex-wrap gap-1.5">
                  @foreach($rule['ips'] as $ip)
                    <span class="rounded-md bg-slate-800/60 px-2 py-0.5 font-mono text-[11px] text-slate-300">{{ $ip }}</span>
                  @endforeach
                </div>
              </div>
            </div>
          </div>
        </div>
      @empty
        <div class="es-card p-8 text-center text-sky-100/70">This Global IP Farm is empty.</div>
      @endforelse
    </div>
  </div>
@endsection
