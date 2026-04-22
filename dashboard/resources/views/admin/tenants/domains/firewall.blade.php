@extends('layouts.admin')

@section('content')
  <div class="mb-6 flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
    <div>
      <a href="{{ route('admin.tenants.domains.show', [$tenant, $domainRecord->hostname]) }}" class="text-sm font-semibold text-cyan-200 hover:text-cyan-100">Back to domain</a>
      <h1 class="es-title mt-2">Firewall: {{ $domainRecord->hostname }}</h1>
      <p class="es-subtitle mt-2">Rules are scoped to client #{{ $tenant->id }} and purge runtime cache through existing actions.</p>
    </div>
    <div class="text-sm text-sky-100/70">
      {{ number_format($usage['used'] ?? count($rules)) }} / {{ number_format($usage['limit'] ?? 0) }} rules
    </div>
  </div>

  <div class="es-card p-5">
    <h2 class="mb-4 text-lg font-bold text-white">Create Rule</h2>
    <form method="POST" action="{{ route('admin.tenants.domains.firewall.store', [$tenant, $domainRecord->hostname]) }}" class="grid gap-3 md:grid-cols-2 xl:grid-cols-6">
      @csrf
      <input class="es-input xl:col-span-2" name="description" placeholder="Description">
      <select class="es-input" name="action">
        @foreach(['block', 'challenge', 'managed_challenge', 'js_challenge', 'allow', 'block_ip_farm'] as $action)
          <option value="{{ $action }}">{{ str_replace('_', ' ', $action) }}</option>
        @endforeach
      </select>
      <select class="es-input" name="field">
        <option value="ip.src">IP Address / CIDR</option>
        <option value="ip.src.country">Country</option>
        <option value="ip.src.asnum">ASN</option>
        <option value="http.request.uri.path">Path</option>
        <option value="http.request.method">Method</option>
        <option value="http.user_agent">User agent</option>
      </select>
      <select class="es-input" name="operator">
        @foreach(['eq', 'ne', 'in', 'not_in', 'contains', 'not_contains', 'starts_with'] as $operator)
          <option value="{{ $operator }}">{{ $operator }}</option>
        @endforeach
      </select>
      <input class="es-input" name="value" placeholder="Value">
      <select class="es-input" name="duration">
        @foreach(['forever', '1h', '6h', '24h', '7d', '30d'] as $duration)
          <option value="{{ $duration }}">{{ $duration }}</option>
        @endforeach
      </select>
      <select class="es-input" name="paused">
        <option value="0">Active</option>
        <option value="1">Paused</option>
      </select>
      <button class="es-btn md:col-span-2 xl:col-span-2" type="submit">Create Firewall Rule</button>
    </form>
  </div>

  <div class="es-card mt-5 p-0">
    <div class="border-b border-white/10 p-5">
      <h2 class="text-lg font-bold text-white">Rules</h2>
    </div>
    <div class="overflow-x-auto">
      <table class="es-table min-w-[1180px]">
        <thead>
        <tr>
          <th>ID</th>
          <th>Description</th>
          <th>Expression</th>
          <th>Status</th>
          <th>Update</th>
          <th>Delete</th>
        </tr>
        </thead>
        <tbody>
        @forelse($rules as $rule)
          @php
            $expr = json_decode((string) ($rule['expression_json'] ?? '{}'), true) ?: [];
            $ruleId = (int) ($rule['id'] ?? 0);
          @endphp
          <tr>
            <td>#{{ $ruleId }}</td>
            <td>{{ $rule['description'] ?? '' }}</td>
            <td>
              <div class="text-sm text-sky-100">{{ $expr['field'] ?? '-' }} {{ $expr['operator'] ?? '-' }}</div>
              <div class="max-w-sm truncate text-xs text-sky-100/60">{{ $expr['value'] ?? '-' }}</div>
            </td>
            <td>
              <form method="POST" action="{{ route('admin.tenants.domains.firewall.toggle', [$tenant, $domainRecord->hostname, $ruleId]) }}">
                @csrf
                <input type="hidden" name="paused" value="{{ !empty($rule['paused']) ? 0 : 1 }}">
                <button class="text-sm font-semibold {{ !empty($rule['paused']) ? 'text-amber-200' : 'text-emerald-200' }}" type="submit">
                  {{ !empty($rule['paused']) ? 'Paused' : 'Active' }}
                </button>
              </form>
            </td>
            <td>
              <form method="POST" action="{{ route('admin.tenants.domains.firewall.update', [$tenant, $domainRecord->hostname, $ruleId]) }}" class="grid gap-2">
                @csrf
                @method('PUT')
                <input class="es-input h-9 text-xs" name="description" value="{{ $rule['description'] ?? '' }}">
                <div class="flex gap-2">
                  <select class="es-input h-9 text-xs" name="action">
                    @foreach(['block', 'challenge', 'managed_challenge', 'js_challenge', 'allow', 'block_ip_farm'] as $action)
                      <option value="{{ $action }}" @selected(($rule['action'] ?? '') === $action)>{{ str_replace('_', ' ', $action) }}</option>
                    @endforeach
                  </select>
                  <select class="es-input h-9 text-xs" name="field">
                    @foreach(['ip.src', 'ip.src.country', 'ip.src.asnum', 'http.request.uri.path', 'http.request.method', 'http.user_agent'] as $field)
                      <option value="{{ $field }}" @selected(($expr['field'] ?? '') === $field)>{{ $field }}</option>
                    @endforeach
                  </select>
                  <select class="es-input h-9 text-xs" name="operator">
                    @foreach(['eq', 'ne', 'in', 'not_in', 'contains', 'not_contains', 'starts_with'] as $operator)
                      <option value="{{ $operator }}" @selected(($expr['operator'] ?? '') === $operator)>{{ $operator }}</option>
                    @endforeach
                  </select>
                </div>
                <input class="es-input h-9 text-xs" name="value" value="{{ $expr['value'] ?? '' }}">
                <input type="hidden" name="duration" value="forever">
                <input type="hidden" name="paused" value="{{ (int) ($rule['paused'] ?? 0) }}">
                <input type="hidden" name="preserve_expiry" value="1">
                <button class="text-left text-xs font-semibold text-cyan-200 hover:text-cyan-100" type="submit">Save Rule</button>
              </form>
            </td>
            <td>
              <form method="POST" action="{{ route('admin.tenants.domains.firewall.destroy', [$tenant, $domainRecord->hostname, $ruleId]) }}">
                @csrf
                @method('DELETE')
                <button class="text-sm font-semibold text-rose-200 hover:text-rose-100" type="submit">Delete</button>
              </form>
            </td>
          </tr>
        @empty
          <tr>
            <td colspan="6" class="py-8 text-center text-sky-100/70">No firewall rules for this domain.</td>
          </tr>
        @endforelse
        </tbody>
      </table>
    </div>
  </div>
@endsection
