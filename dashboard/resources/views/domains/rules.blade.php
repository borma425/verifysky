@extends('layouts.app')

@section('content')
  @php
    $domainName = $domain['domain_name'] ?? '';
    $zoneId = $domain['zone_id'] ?? '';
    $isForced = (int)($domain['force_captcha'] ?? 0) === 1;
  @endphp

  <div class="es-card es-animate relative left-1/2 mb-4 w-[98vw] max-w-none -translate-x-1/2 p-5 md:p-6">
    <div class="mb-3 flex flex-wrap items-center gap-2">
      <a href="{{ route('domains.index') }}" class="es-btn es-btn-secondary">Back to Domains</a>
      <span class="es-chip">{{ $domain['status'] ?? 'unknown' }}</span>
      <span class="es-chip {{ $isForced ? 'border-emerald-400/35 bg-emerald-500/20 text-emerald-100' : '' }}">
        Force CAPTCHA: {{ $isForced ? 'Enabled' : 'Disabled' }}
      </span>
    </div>

    <h2 class="es-title">Rules Management: {{ $domainName }}</h2>
    <p class="mt-1 text-sm es-muted">Zone ID: <span class="font-mono text-sky-200">{{ $zoneId }}</span></p>

    @if(!empty($loadErrors))
      <div class="mt-3 rounded-xl border border-amber-400/35 bg-amber-500/15 px-4 py-3 text-sm text-amber-100">
        @foreach($loadErrors as $msg)
          <div>{{ $msg }}</div>
        @endforeach
      </div>
    @endif
  </div>

  <div class="es-card es-animate es-animate-delay mb-4 p-5 md:p-6">
    <h3 class="mb-3 text-lg font-bold text-sky-100">Create Firewall Rule</h3>
    <form method="POST" action="{{ route('domains.rules.store', ['domain' => $domainName]) }}">
      @csrf
      <div class="grid gap-3 md:grid-cols-2">
        <div>
          <label class="mb-1 block text-sm text-sky-100">Action</label>
          <select name="action" class="es-input text-sm" required>
            <option value="managed_challenge">managed_challenge</option>
            <option value="challenge">challenge</option>
            <option value="js_challenge">js_challenge</option>
            <option value="block">block</option>
            <option value="log">log</option>
            <option value="allow">allow</option>
            <option value="bypass">bypass</option>
          </select>
        </div>
        <div>
          <label class="mb-1 block text-sm text-sky-100">Description (optional)</label>
          <input type="text" name="description" class="es-input text-sm" placeholder="Example: Block abusive ASN">
        </div>
      </div>
      <div class="mt-3">
        <label class="mb-1 block text-sm text-sky-100">Expression</label>
        <textarea name="expression" rows="4" class="es-input font-mono text-xs" placeholder='Example: (ip.src in {1.2.3.4 5.6.7.8}) or (http.user_agent contains "python")' required></textarea>
      </div>
      <label class="mt-3 inline-flex items-center gap-2 text-sm es-muted">
        <input type="checkbox" name="paused" value="1" class="rounded border-white/20 bg-slate-900/70">
        Create as paused
      </label>
      <div class="mt-4">
        <button type="submit" class="es-btn">Create Rule</button>
      </div>
    </form>
  </div>

  <div class="es-card es-animate es-animate-delay mb-4 p-5 md:p-6">
    <h3 class="mb-3 text-lg font-bold text-sky-100">Worker Routes</h3>
    <div class="overflow-x-auto">
      <table class="es-table min-w-[1200px]">
        <thead>
          <tr>
            <th>Pattern</th>
            <th>Script</th>
            <th>Route ID</th>
          </tr>
        </thead>
        <tbody>
        @forelse($workerRoutes as $route)
          <tr>
            <td class="font-mono text-xs text-sky-100">{{ $route['pattern'] ?? '' }}</td>
            <td>{{ $route['script'] ?? '' }}</td>
            <td class="font-mono text-xs es-muted">{{ $route['id'] ?? '' }}</td>
          </tr>
        @empty
          <tr><td colspan="3" class="text-slate-300">No worker routes found for this zone.</td></tr>
        @endforelse
        </tbody>
      </table>
    </div>
  </div>

  <div class="es-card es-animate es-animate-delay-2 relative left-1/2 w-[98vw] max-w-none -translate-x-1/2 p-5 md:p-6">
    <h3 class="mb-3 text-lg font-bold text-sky-100">Firewall Rules</h3>
    <div class="overflow-x-auto">
      <table class="es-table min-w-[1350px]">
        <thead>
          <tr>
            <th>Description</th>
            <th>Action</th>
            <th>Status</th>
            <th>Expression</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
        @forelse($firewallRules as $rule)
          @php $paused = (bool)($rule['paused'] ?? false); @endphp
          <tr class="align-top">
            <td>
              <div class="font-medium text-slate-100">{{ $rule['description'] ?? 'No description' }}</div>
              <div class="font-mono text-xs es-muted">{{ $rule['id'] ?? '' }}</div>
            </td>
            <td>
              <span class="es-chip border-indigo-400/35 bg-indigo-500/20 text-indigo-100">{{ $rule['action'] ?? '' }}</span>
            </td>
            <td>
              <span class="es-chip {{ $paused ? 'border-amber-400/35 bg-amber-500/20 text-amber-100' : 'border-emerald-400/35 bg-emerald-500/20 text-emerald-100' }}">
                {{ $paused ? 'Paused' : 'Enabled' }}
              </span>
            </td>
            <td class="font-mono text-xs text-slate-200">{{ $rule['filter']['expression'] ?? '' }}</td>
            <td>
              <div class="flex flex-wrap gap-2">
                <form method="POST" action="{{ route('domains.rules.toggle', ['domain' => $domainName, 'ruleId' => $rule['id'] ?? '']) }}">
                  @csrf
                  <input type="hidden" name="paused" value="{{ $paused ? 0 : 1 }}">
                  <button type="submit" class="es-btn {{ $paused ? 'es-btn-success' : 'es-btn-warning' }}">
                    {{ $paused ? 'Enable' : 'Pause' }}
                  </button>
                </form>
                <form method="POST" action="{{ route('domains.rules.destroy', ['domain' => $domainName, 'ruleId' => $rule['id'] ?? '']) }}">
                  @csrf
                  @method('DELETE')
                  <button type="submit" class="es-btn es-btn-danger">Delete</button>
                </form>
              </div>
            </td>
          </tr>
        @empty
          <tr><td colspan="5" class="text-slate-300">No firewall rules found for this zone.</td></tr>
        @endforelse
        </tbody>
      </table>
    </div>
  </div>
@endsection
