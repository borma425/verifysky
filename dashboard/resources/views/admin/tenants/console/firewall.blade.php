@extends('layouts.admin')

@section('content')
  <div class="mb-6 flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
    <div>
      <a href="{{ route('admin.tenants.show', $tenant) }}" class="text-sm font-semibold text-cyan-200 hover:text-cyan-100">Back to {{ $tenant->name }}</a>
      <h1 class="es-title mt-2">Firewall</h1>
      <p class="es-subtitle mt-2">Manage rules for all domains or one selected domain.</p>
    </div>
    <div class="flex flex-wrap gap-2">
      <a href="{{ route('admin.tenants.sensitive_paths.index', $tenant) }}" class="es-btn es-btn-secondary">Protected Paths</a>
      <a href="{{ route('admin.tenants.ip_farm.index', $tenant) }}" class="es-btn es-btn-secondary">Blocked IPs</a>
    </div>
  </div>

  @if(!empty($loadErrors))
    <div class="mb-4 rounded-xl border border-amber-400/35 bg-amber-500/15 px-4 py-3 text-sm text-amber-100">
      @foreach($loadErrors as $msg)
        <div>{{ $msg }}</div>
      @endforeach
    </div>
  @endif

  <div class="grid gap-5 xl:grid-cols-[420px_1fr]">
    <div class="space-y-5">
      <div class="es-card p-5">
        <h2 class="mb-4 text-lg font-bold text-white">Create Rule</h2>
        <form method="POST" action="{{ route('admin.tenants.firewall.store', $tenant) }}" class="space-y-3">
          @csrf
          <label class="block text-sm text-sky-100">Scope
            <select class="es-input mt-1" name="scope">
              <option value="tenant">All domains</option>
              <option value="domain" @selected($selectedDomain !== '')>Specific domain</option>
            </select>
            <span class="mt-1 block text-xs leading-5 text-sky-100/60">All domains covers registered hostnames for this tenant only, including explicitly added subdomains.</span>
          </label>
          <label class="block text-sm text-sky-100">Domain
            <select class="es-input mt-1" name="domain_name">
              @foreach($domainRecords as $domain)
                <option value="{{ $domain->hostname }}" @selected($selectedDomain === $domain->hostname)>{{ $domain->hostname }}</option>
              @endforeach
            </select>
          </label>
          <input class="es-input" name="description" placeholder="Description">
          <div class="grid gap-3 md:grid-cols-2">
            <select class="es-input" name="action">
              @foreach(['managed_challenge', 'challenge', 'js_challenge', 'block', 'block_ip_farm', 'allow'] as $action)
                <option value="{{ $action }}">{{ str_replace('_', ' ', $action) }}</option>
              @endforeach
            </select>
            <select class="es-input" name="duration">
              @foreach(['forever', '1h', '6h', '24h', '7d', '30d'] as $duration)
                <option value="{{ $duration }}">{{ $duration }}</option>
              @endforeach
            </select>
          </div>
          <div class="grid gap-3 md:grid-cols-2">
            <select class="es-input" name="field">
              <option value="ip.src">IP Address / CIDR</option>
              <option value="ip.src.country">Country</option>
              <option value="ip.src.asnum">ASN</option>
              <option value="http.request.uri.path">URI Path</option>
              <option value="http.request.method">Method</option>
              <option value="http.user_agent">User Agent</option>
            </select>
            <select class="es-input" name="operator">
              @foreach(['eq', 'ne', 'contains', 'starts_with', 'not_contains', 'in', 'not_in'] as $operator)
                <option value="{{ $operator }}">{{ $operator }}</option>
              @endforeach
            </select>
          </div>
          <input class="es-input" name="value" placeholder="Value" required>
          <label class="inline-flex items-center gap-2 text-sm es-muted">
            <input type="checkbox" name="paused" value="1" class="rounded border-white/20 bg-slate-900/70">
            Create as paused
          </label>
          <button class="es-btn w-full" type="submit">Add Firewall Rule</button>
        </form>
      </div>

      <div class="es-card p-5 text-sm text-sky-100/70">
        <div class="font-semibold text-white">{{ $firewallUsage['plan_name'] ?? 'Plan' }}</div>
        <div class="mt-1">{{ $firewallUsage['used'] ?? 0 }} / {{ $firewallUsage['limit'] ?? 0 }} customer rules used.</div>
        <div class="mt-2 text-xs text-emerald-200">Admins can exceed firewall rule limits for this user.</div>
      </div>
    </div>

    <div class="es-card p-0">
      <div class="flex flex-col gap-3 border-b border-white/10 p-5 md:flex-row md:items-center md:justify-between">
        <div>
          <h2 class="text-lg font-bold text-white">Rules</h2>
          <p class="mt-1 text-sm text-sky-100/65">{{ count($manualRules) + count($aiRules) }} rule(s) loaded{{ $selectedDomain ? ' for '.$selectedDomain : '' }}.</p>
        </div>
        <form method="GET" action="{{ route('admin.tenants.firewall.index', $tenant) }}" class="flex gap-2">
          <select class="es-input h-10 text-sm" name="domain">
            <option value="">All domains</option>
            @foreach($domainRecords as $domain)
              <option value="{{ $domain->hostname }}" @selected($selectedDomain === $domain->hostname)>{{ $domain->hostname }}</option>
            @endforeach
          </select>
          <button class="es-btn es-btn-secondary h-10 px-4" type="submit">Filter</button>
        </form>
      </div>
      <div class="overflow-x-auto">
        <table class="es-table min-w-[1250px]">
          <thead>
          <tr>
            <th>ID</th>
            <th>Scope</th>
            <th>Domain</th>
            <th>Description</th>
            <th>Action</th>
            <th>Expression</th>
            <th>Status</th>
            <th>Update</th>
            <th>Delete</th>
          </tr>
          </thead>
          <tbody>
          @forelse($firewallRules as $rule)
            @php
              $scope = ($rule['domain_name'] ?? '') === 'global' ? 'tenant' : 'domain';
              $routeDomain = $rule['domain_name'] ?: 'global';
            @endphp
            <tr class="align-top">
              <td>#{{ $rule['id'] }}</td>
              <td><span class="es-chip">{{ $scope === 'tenant' ? 'All domains' : 'Specific domain' }}</span></td>
              <td>{{ $scope === 'tenant' ? 'All domains' : $rule['domain_name'] }}</td>
              <td>{{ $rule['description'] }}</td>
              <td>{{ $rule['action'] }}</td>
              <td>
                <div class="font-mono text-xs text-sky-100">{{ $rule['field'] }} {{ $rule['operator'] }}</div>
                <div class="max-w-sm truncate font-mono text-xs text-emerald-200" title="{{ $rule['value'] }}">{{ $rule['value_display'] }}</div>
              </td>
              <td>
                <form method="POST" action="{{ route('admin.tenants.firewall.toggle', [$tenant, $routeDomain, $rule['id']]) }}">
                  @csrf
                  <input type="hidden" name="paused" value="{{ $rule['next_paused_value'] }}">
                  <button class="text-sm font-semibold {{ $rule['is_paused'] ? 'text-amber-200' : 'text-emerald-200' }}" type="submit">{{ $rule['status_label'] }}</button>
                </form>
              </td>
              <td>
                <form method="POST" action="{{ route('admin.tenants.firewall.update', [$tenant, $routeDomain, $rule['id']]) }}" class="grid min-w-[360px] gap-2">
                  @csrf
                  @method('PUT')
                  <input type="hidden" name="scope" value="{{ $scope }}">
                  <input type="hidden" name="domain_name" value="{{ $rule['domain_name'] }}">
                  <input class="es-input h-9 text-xs" name="description" value="{{ $rule['description'] }}">
                  <div class="grid grid-cols-3 gap-2">
                    <select class="es-input h-9 text-xs" name="action">
                      @foreach(['block', 'challenge', 'managed_challenge', 'js_challenge', 'allow', 'block_ip_farm'] as $action)
                        <option value="{{ $action }}" @selected($rule['action'] === $action)>{{ $action }}</option>
                      @endforeach
                    </select>
                    <select class="es-input h-9 text-xs" name="field">
                      @foreach(['ip.src', 'ip.src.country', 'ip.src.asnum', 'http.request.uri.path', 'http.request.method', 'http.user_agent'] as $field)
                        <option value="{{ $field }}" @selected($rule['field'] === $field)>{{ $field }}</option>
                      @endforeach
                    </select>
                    <select class="es-input h-9 text-xs" name="operator">
                      @foreach(['eq', 'ne', 'in', 'not_in', 'contains', 'not_contains', 'starts_with'] as $operator)
                        <option value="{{ $operator }}" @selected($rule['operator'] === $operator)>{{ $operator }}</option>
                      @endforeach
                    </select>
                  </div>
                  <input class="es-input h-9 text-xs" name="value" value="{{ $rule['value'] }}">
                  <input type="hidden" name="duration" value="forever">
                  <input type="hidden" name="paused" value="{{ (int) $rule['is_paused'] }}">
                  <input type="hidden" name="preserve_expiry" value="1">
                  <button class="text-left text-xs font-semibold text-cyan-200 hover:text-cyan-100" type="submit">Save</button>
                </form>
              </td>
              <td>
                <form method="POST" action="{{ route('admin.tenants.firewall.destroy', [$tenant, $routeDomain, $rule['id']]) }}">
                  @csrf
                  @method('DELETE')
                  <button class="text-sm font-semibold text-rose-200 hover:text-rose-100" type="submit">Delete</button>
                </form>
              </td>
            </tr>
          @empty
            <tr><td colspan="9" class="py-8 text-center text-sky-100/70">No firewall rules for this client.</td></tr>
          @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div>
@endsection
