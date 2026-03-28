@extends('layouts.app')

@section('content')
  <div class="mb-4">
    <a href="{{ route('domains.index') }}" class="es-btn es-btn-secondary text-sm">&larr; Back to Domains</a>
  </div>

  <div class="es-card es-animate mb-4 p-5 md:p-6">
    <h2 class="es-title mb-3">Protection Tuning for {{ $domain }}</h2>
    <p class="es-subtitle mb-5">Fine-tune the security thresholds dynamically. These values will be applied instantly by the Edge Worker for this domain without requiring a deployment.</p>

    @if(session('status'))
      <div class="mb-4 rounded-xl border border-emerald-400/30 bg-emerald-500/15 px-4 py-3 text-sm text-emerald-200">{{ session('status') }}</div>
    @endif
    @if(session('error'))
      <div class="mb-4 rounded-xl border border-rose-400/30 bg-rose-500/15 px-4 py-3 text-sm text-rose-200">{{ session('error') }}</div>
    @endif
    @if($errors->any())
      <div class="mb-4 rounded-xl border border-rose-400/30 bg-rose-500/15 px-4 py-3 text-sm text-rose-200">
        <ul class="list-inside list-disc">
          @foreach($errors->all() as $error)
            <li>{{ $error }}</li>
          @endforeach
        </ul>
      </div>
    @endif

    <form method="POST" action="{{ route('domains.update_tuning', ['domain' => $domain]) }}">
      @csrf

      <h3 class="mt-4 mb-2 text-lg font-semibold text-white/90">General Limits</h3>
      <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
        <div>
          <label class="mb-1 block text-sm font-medium text-sky-100">Visit CAPTCHA Threshold</label>
          <input type="number" name="visit_captcha_threshold" value="{{ $thresholds['visit_captcha_threshold'] ?? 6 }}" min="1" max="100" class="es-input w-full" required>
          <p class="mt-1 text-xs es-muted">Visits in 3 mins before CAPTCHA.</p>
        </div>
        <div>
          <label class="mb-1 block text-sm font-medium text-sky-100">Daily Visit Limit</label>
          <input type="number" name="daily_visit_limit" value="{{ $thresholds['daily_visit_limit'] ?? 15 }}" min="1" max="5000" class="es-input w-full" required>
          <p class="mt-1 text-xs es-muted">Max visits per 24 hours (stops slow-drips).</p>
        </div>
        <div>
          <label class="mb-1 block text-sm font-medium text-sky-100">Session TTL (Hours)</label>
          <input type="number" name="session_ttl_hours" value="{{ $thresholds['session_ttl_hours'] ?? 1 }}" min="0.01" max="168" step="0.01" class="es-input w-full" required>
          <p class="mt-1 text-xs es-muted">How long a validated user session lasts (e.g. 1 = 1 hour).</p>
        </div>
        <div>
          <label class="mb-1 block text-sm font-medium text-sky-100">IP Hard Ban Rate</label>
          <input type="number" name="ip_hard_ban_rate" value="{{ $thresholds['ip_hard_ban_rate'] ?? 120 }}" min="10" max="2000" class="es-input w-full" required>
          <p class="mt-1 text-xs es-muted">Requests/minute per IP that trigger an absolute edge block.</p>
        </div>
        <div>
          <label class="mb-1 block text-sm font-medium text-sky-100">ASN Hourly Visit Limit</label>
          <input type="number" name="asn_hourly_visit_limit" value="{{ $thresholds['asn_hourly_visit_limit'] ?? 200 }}" min="50" max="10000" class="es-input w-full" required>
          <p class="mt-1 text-xs es-muted">Max unverified visits per ASN/ISP in 1 hour.</p>
        </div>
      </div>

      <h3 class="mt-6 mb-2 text-lg font-semibold text-white/90">Flood Protection </h3>
      <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
        <div>
          <label class="mb-1 block text-sm font-medium text-sky-100">Burst Challenge</label>
          <input type="number" name="flood_burst_challenge" value="{{ $thresholds['flood_burst_challenge'] ?? 8 }}" min="1" max="500" class="es-input w-full" required>
          <p class="mt-1 text-xs es-muted">Requests in 15s to challenge.</p>
        </div>
        <div>
          <label class="mb-1 block text-sm font-medium text-sky-100">Burst Block</label>
          <input type="number" name="flood_burst_block" value="{{ $thresholds['flood_burst_block'] ?? 15 }}" min="1" max="500" class="es-input w-full" required>
          <p class="mt-1 text-xs es-muted">Requests in 15s to block.</p>
        </div>
        <div>
          <label class="mb-1 block text-sm font-medium text-sky-100">Sustained Challenge</label>
          <input type="number" name="flood_sustained_challenge" value="{{ $thresholds['flood_sustained_challenge'] ?? 8 }}" min="1" max="1000" class="es-input w-full" required>
          <p class="mt-1 text-xs es-muted">Requests in 60s to challenge.</p>
        </div>
        <div>
          <label class="mb-1 block text-sm font-medium text-sky-100">Sustained Block</label>
          <input type="number" name="flood_sustained_block" value="{{ $thresholds['flood_sustained_block'] ?? 40 }}" min="1" max="1000" class="es-input w-full" required>
          <p class="mt-1 text-xs es-muted">Requests in 60s to block.</p>
        </div>
      </div>

      <h3 class="mt-6 mb-2 text-lg font-semibold text-white/90">Penalties & Restrictions</h3>
      <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
        <div>
          <label class="mb-1 block text-sm font-medium text-sky-100">Max Challenge Failures</label>
          <input type="number" name="max_challenge_failures" value="{{ $thresholds['max_challenge_failures'] ?? 8 }}" min="1" max="50" class="es-input w-full" required>
          <p class="mt-1 text-xs es-muted">Failures before IP gets temp-banned.</p>
        </div>
        <div>
          <label class="mb-1 block text-sm font-medium text-sky-100">Temp Ban TTL (Hours)</label>
          <input type="number" name="temp_ban_ttl_hours" value="{{ $thresholds['temp_ban_ttl_hours'] ?? 24 }}" min="0.01" max="720" step="0.01" class="es-input w-full" required>
          <p class="mt-1 text-xs es-muted">Duration of a temporary ban (e.g. 24 = 24 hours).</p>
        </div>
        <div>
          <label class="mb-1 block text-sm font-medium text-sky-100">AI Rule TTL (Days)</label>
          <input type="number" name="ai_rule_ttl_days" value="{{ $thresholds['ai_rule_ttl_days'] ?? 7 }}" min="0.1" max="365" step="0.1" class="es-input w-full" required>
          <p class="mt-1 text-xs es-muted">How long auto-generated AI rules live (e.g. 7 = 7 days).</p>
        </div>
      </div>

      <div class="mt-6 mb-6">
        <label class="flex items-center space-x-3 cursor-pointer">
          <input type="checkbox" name="ad_traffic_strict_mode" class="es-checkbox" value="1" {{ ($thresholds['ad_traffic_strict_mode'] ?? true) ? 'checked' : '' }}>
          <span class="text-sm font-medium text-sky-100">Enable Ad Traffic Strict Mode</span>
        </label>
        <p class="mt-1 ml-7 text-xs es-muted">Automatically lowers visit thresholds and increases risk scores for requests containing ad trackers (gclid, fbclid, etc.).</p>
      </div>

      <h3 class="mt-6 mb-2 text-lg font-semibold text-white/90">Auto-Escalation (Auto-Aggressive)</h3>
      <p class="mb-3 text-xs es-muted">When distributed attack pressure is detected (multiple suspicious subnets), the domain automatically escalates to Aggressive mode for a limited window, then returns to Balanced.</p>
      <div class="grid gap-4 md:grid-cols-3">
        <div>
          <label class="mb-1 block text-sm font-medium text-sky-100">Pressure Window (Minutes)</label>
          <input type="number" name="auto_aggr_pressure_minutes" value="{{ $thresholds['auto_aggr_pressure_minutes'] ?? 3 }}" min="1" max="30" step="0.5" class="es-input w-full" required>
          <p class="mt-1 text-xs es-muted">Time window to count suspicious subnets (default: 3 min).</p>
        </div>
        <div>
          <label class="mb-1 block text-sm font-medium text-sky-100">Aggressive Duration (Minutes)</label>
          <input type="number" name="auto_aggr_active_minutes" value="{{ $thresholds['auto_aggr_active_minutes'] ?? 10 }}" min="1" max="120" step="0.5" class="es-input w-full" required>
          <p class="mt-1 text-xs es-muted">How long the domain stays in Aggressive mode (default: 10 min).</p>
        </div>
        <div>
          <label class="mb-1 block text-sm font-medium text-sky-100">Trigger Subnets</label>
          <input type="number" name="auto_aggr_trigger_subnets" value="{{ $thresholds['auto_aggr_trigger_subnets'] ?? 8 }}" min="2" max="50" class="es-input w-full" required>
          <p class="mt-1 text-xs es-muted">Unique suspicious subnets needed to trigger escalation (default: 8).</p>
        </div>
      </div>

      <div class="mt-8 flex items-center justify-end">
        <button class="es-btn" type="submit">Save Threshold Settings</button>
      </div>
    </form>
  </div>
@endsection
