@extends('layouts.admin')

@section('content')
  @php
    $threshold = fn (string $key, mixed $default): mixed => $thresholds[$key] ?? $default;
    $balanced = $challengeProfiles['balanced'] ?? ['solve' => 150, 'points' => 3, 'tolerance' => 24];
    $aggressive = $challengeProfiles['aggressive'] ?? ['solve' => 200, 'points' => 4, 'tolerance' => 24];
  @endphp

  <div class="mb-6 flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
    <div>
      <a href="{{ route('admin.tenants.show', $tenant) }}" class="text-sm font-semibold text-cyan-200 hover:text-cyan-100">Back to {{ $tenant->name }}</a>
      <h1 class="es-title mt-2">{{ $domainRecord->hostname }}</h1>
      <p class="es-subtitle mt-2">Admin-scoped domain management for user #{{ $tenant->id }}.</p>
    </div>
    <div class="flex flex-wrap gap-2">
      <form method="POST" action="{{ route('admin.tenants.domains.sync_route', [$tenant, $domainRecord->hostname]) }}">
        @csrf
        <button class="es-btn es-btn-secondary" type="submit">Sync Route</button>
      </form>
      <form method="POST" action="{{ route('admin.tenants.domains.runtime_cache.purge', [$tenant, $domainRecord->hostname]) }}">
        @csrf
        <button class="es-btn" type="submit">Purge Runtime Cache</button>
      </form>
    </div>
  </div>

  <div class="grid gap-4 xl:grid-cols-3">
    <div class="es-card p-5">
      <h2 class="mb-4 text-lg font-bold text-white">Routing</h2>
      <form method="POST" action="{{ route('admin.tenants.domains.origin.update', [$tenant, $domainRecord->hostname]) }}" class="space-y-3">
        @csrf
        <label class="block text-sm text-sky-100">Origin server</label>
        <input class="es-input" name="origin_server" value="{{ old('origin_server', $originServer ?: $domainRecord->origin_server) }}" placeholder="203.0.113.10">
        <button class="es-btn" type="submit">Update Origin</button>
      </form>
      <div class="mt-4 text-sm text-sky-100/70">
        Hostname: {{ $domainRecord->hostname_status ?: ($config['hostname_status'] ?? 'pending') }}<br>
        SSL: {{ $domainRecord->ssl_status ?: ($config['ssl_status'] ?? 'pending') }}
      </div>
    </div>

    <div class="es-card p-5">
      <h2 class="mb-4 text-lg font-bold text-white">Security Mode</h2>
      <form method="POST" action="{{ route('admin.tenants.domains.security_mode.update', [$tenant, $domainRecord->hostname]) }}" class="space-y-3">
        @csrf
        <select name="security_mode" class="es-input">
          @foreach(['monitor' => 'Monitor', 'balanced' => 'Balanced', 'aggressive' => 'Aggressive'] as $value => $label)
            <option value="{{ $value }}" @selected(old('security_mode', $config['security_mode'] ?? $domainRecord->security_mode ?? 'balanced') === $value)>{{ $label }}</option>
          @endforeach
        </select>
        <button class="es-btn" type="submit">Update Mode</button>
      </form>

      <form method="POST" action="{{ route('admin.tenants.domains.force_captcha.update', [$tenant, $domainRecord->hostname]) }}" class="mt-4 space-y-3">
        @csrf
        <select name="force_captcha" class="es-input">
          <option value="0" @selected((int) old('force_captcha', $config['force_captcha'] ?? $domainRecord->force_captcha ?? 0) === 0)>Force CAPTCHA off</option>
          <option value="1" @selected((int) old('force_captcha', $config['force_captcha'] ?? $domainRecord->force_captcha ?? 0) === 1)>Force CAPTCHA on</option>
        </select>
        <button class="es-btn es-btn-secondary" type="submit">Update CAPTCHA</button>
      </form>
    </div>

    <div class="es-card p-5">
      <h2 class="mb-4 text-lg font-bold text-white">Cache</h2>
      <div class="text-sm text-sky-100/70">Runtime bundle cache purges are queued through the same path used by customer domain updates.</div>
      <form method="POST" action="{{ route('admin.tenants.domains.runtime_cache.purge', [$tenant, $domainRecord->hostname]) }}" class="mt-4">
        @csrf
        <button class="es-btn" type="submit">Queue PurgeRuntimeBundleCache</button>
      </form>
      <div class="mt-4 text-xs text-sky-100/55">Protected hostname ID: {{ $config['custom_hostname_id'] ?? $domainRecord->cloudflare_custom_hostname_id ?? 'not available' }}</div>
    </div>
  </div>

  <div class="es-card mt-5 p-5">
    <div class="mb-4 flex items-center justify-between">
      <div>
        <h2 class="text-lg font-bold text-white">Firewall</h2>
        <p class="mt-1 text-sm text-sky-100/65">{{ count($rules) }} custom rule(s) loaded for this domain.</p>
      </div>
      <a href="{{ route('admin.tenants.domains.firewall.index', [$tenant, $domainRecord->hostname]) }}" class="es-btn es-btn-secondary">Open Unified Firewall</a>
    </div>
    <div class="grid gap-2 md:grid-cols-2 xl:grid-cols-3">
      @forelse(array_slice($rules, 0, 6) as $rule)
        <div class="rounded-lg border border-white/10 px-3 py-2">
          <div class="font-semibold text-white">{{ $rule['description'] ?? 'Firewall rule #'.($rule['id'] ?? '') }}</div>
          <div class="text-xs text-sky-100/60">{{ $rule['action'] ?? '' }} / {{ !empty($rule['paused']) ? 'paused' : 'active' }}</div>
        </div>
      @empty
        <div class="text-sm text-sky-100/70">No custom firewall rules.</div>
      @endforelse
    </div>
  </div>

  <div class="es-card mt-5 p-5">
    <h2 class="mb-4 text-lg font-bold text-white">Tuning</h2>
    <form method="POST" action="{{ route('admin.tenants.domains.tuning.update', [$tenant, $domainRecord->hostname]) }}" class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
      @csrf
      <label class="text-sm text-sky-100">Visit CAPTCHA threshold<input class="es-input mt-1" type="number" name="visit_captcha_threshold" value="{{ old('visit_captcha_threshold', $threshold('visit_captcha_threshold', 20)) }}"></label>
      <label class="text-sm text-sky-100">Daily visit limit<input class="es-input mt-1" type="number" name="daily_visit_limit" value="{{ old('daily_visit_limit', $threshold('daily_visit_limit', 5000)) }}"></label>
      <label class="text-sm text-sky-100">ASN hourly limit<input class="es-input mt-1" type="number" name="asn_hourly_visit_limit" value="{{ old('asn_hourly_visit_limit', $threshold('asn_hourly_visit_limit', 1000)) }}"></label>
      <label class="text-sm text-sky-100">IP hard ban rate<input class="es-input mt-1" type="number" name="ip_hard_ban_rate" value="{{ old('ip_hard_ban_rate', $threshold('ip_hard_ban_rate', 200)) }}"></label>
      <label class="text-sm text-sky-100">Burst challenge<input class="es-input mt-1" type="number" name="flood_burst_challenge" value="{{ old('flood_burst_challenge', $threshold('flood_burst_challenge', 60)) }}"></label>
      <label class="text-sm text-sky-100">Burst block<input class="es-input mt-1" type="number" name="flood_burst_block" value="{{ old('flood_burst_block', $threshold('flood_burst_block', 120)) }}"></label>
      <label class="text-sm text-sky-100">Sustained challenge<input class="es-input mt-1" type="number" name="flood_sustained_challenge" value="{{ old('flood_sustained_challenge', $threshold('flood_sustained_challenge', 180)) }}"></label>
      <label class="text-sm text-sky-100">Sustained block<input class="es-input mt-1" type="number" name="flood_sustained_block" value="{{ old('flood_sustained_block', $threshold('flood_sustained_block', 360)) }}"></label>
      <label class="text-sm text-sky-100">Max challenge failures<input class="es-input mt-1" type="number" name="max_challenge_failures" value="{{ old('max_challenge_failures', $threshold('max_challenge_failures', 5)) }}"></label>
      <label class="text-sm text-sky-100">Temp ban TTL hours<input class="es-input mt-1" type="number" step="0.01" name="temp_ban_ttl_hours" value="{{ old('temp_ban_ttl_hours', $threshold('temp_ban_ttl_hours', 24)) }}"></label>
      <label class="text-sm text-sky-100">AI rule TTL days<input class="es-input mt-1" type="number" step="0.1" name="ai_rule_ttl_days" value="{{ old('ai_rule_ttl_days', $threshold('ai_rule_ttl_days', 7)) }}"></label>
      <label class="text-sm text-sky-100">Session TTL hours<input class="es-input mt-1" type="number" step="0.01" name="session_ttl_hours" value="{{ old('session_ttl_hours', $threshold('session_ttl_hours', 24)) }}"></label>
      <label class="text-sm text-sky-100">Pressure minutes<input class="es-input mt-1" type="number" step="0.1" name="auto_aggr_pressure_minutes" value="{{ old('auto_aggr_pressure_minutes', $threshold('auto_aggr_pressure_minutes', 5)) }}"></label>
      <label class="text-sm text-sky-100">Active minutes<input class="es-input mt-1" type="number" step="0.1" name="auto_aggr_active_minutes" value="{{ old('auto_aggr_active_minutes', $threshold('auto_aggr_active_minutes', 30)) }}"></label>
      <label class="text-sm text-sky-100">Trigger subnets<input class="es-input mt-1" type="number" name="auto_aggr_trigger_subnets" value="{{ old('auto_aggr_trigger_subnets', $threshold('auto_aggr_trigger_subnets', 4)) }}"></label>
      <label class="text-sm text-sky-100">API count<input class="es-input mt-1" type="number" name="api_count" value="{{ old('api_count', $threshold('api_count', 0)) }}"></label>
      <input type="hidden" name="challenge_min_solve_ms_balanced" value="{{ old('challenge_min_solve_ms_balanced', $balanced['solve']) }}">
      <input type="hidden" name="challenge_min_telemetry_points_balanced" value="{{ old('challenge_min_telemetry_points_balanced', $balanced['points']) }}">
      <input type="hidden" name="challenge_x_tolerance_balanced" value="{{ old('challenge_x_tolerance_balanced', $balanced['tolerance']) }}">
      <input type="hidden" name="challenge_min_solve_ms_aggressive" value="{{ old('challenge_min_solve_ms_aggressive', $aggressive['solve']) }}">
      <input type="hidden" name="challenge_min_telemetry_points_aggressive" value="{{ old('challenge_min_telemetry_points_aggressive', $aggressive['points']) }}">
      <input type="hidden" name="challenge_x_tolerance_aggressive" value="{{ old('challenge_x_tolerance_aggressive', $aggressive['tolerance']) }}">
      <label class="flex items-center gap-2 text-sm text-sky-100 md:col-span-2">
        <input type="checkbox" name="ad_traffic_strict_mode" value="1" @checked((bool) $threshold('ad_traffic_strict_mode', false))>
        Ad traffic strict mode
      </label>
      <div class="md:col-span-2 xl:col-span-4">
        <button class="es-btn" type="submit">Save Tuning</button>
      </div>
    </form>
  </div>
@endsection
