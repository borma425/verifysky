@extends('layouts.app')

@section('content')
  <div class="es-card es-animate p-5 md:p-6">
    <h2 class="es-title mb-2">Settings & Secrets</h2>
    <p class="es-subtitle mb-4">Use this page to keep operational values for your team. For production-grade secret hygiene, move secrets to vault/KMS.</p>
    <form method="POST" action="{{ route('settings.update') }}">
      @csrf
      <div class="grid gap-3 md:grid-cols-2">
        <div><label class="mb-1 block text-sm text-sky-100">OpenRouter Model</label><input class="es-input" name="openrouter_model" value="{{ $settings['openrouter_model'] ?? '' }}" placeholder="qwen/qwen3-next-80b-a3b-instruct:free"></div>
        <div><label class="mb-1 block text-sm text-sky-100">OpenRouter Fallback Models</label><input class="es-input" name="openrouter_fallback_models" value="{{ $settings['openrouter_fallback_models'] ?? '' }}" placeholder="openai/gpt-oss-120b:free,nvidia/nemotron-3-super:free"></div>
      </div>
      <div class="mt-3 grid gap-3 md:grid-cols-2">
        <div><label class="mb-1 block text-sm text-sky-100">Worker Script Name</label><input class="es-input" name="worker_script_name" value="{{ $settings['worker_script_name'] ?? 'edge-shield' }}" placeholder="edge-shield"></div>
        <div>
          <label class="mb-1 block text-sm text-sky-100">ES Admin Token</label>
          <input class="es-input" type="password" autocomplete="new-password" name="es_admin_token" value="" placeholder="{{ ($sensitiveConfigured['es_admin_token'] ?? false) ? 'Configured (leave blank to keep)' : 'token used for /es-admin/* endpoints' }}">
        </div>
      </div>
      <div class="mt-3 grid gap-3 md:grid-cols-2">
        <div>
          <label class="mb-1 block text-sm text-sky-100">Admin Login Path</label>
          <input class="es-input" name="admin_login_path" value="{{ $settings['admin_login_path'] ?? $currentLoginPath }}" placeholder="wow/login">
          <p class="mt-1 text-xs es-muted">Current login URL: <code>{{ url('/'.$currentLoginPath) }}</code></p>
        </div>
        <div></div>
      </div>
      <div class="mt-3 grid gap-3 md:grid-cols-2">
        <div class="es-card-soft px-3 py-2">
          <label class="mb-1 block text-sm text-sky-100">Disable WAF Auto-Deploy</label>
          <select class="es-input" name="es_disable_waf_autodeploy">
            @php($disableWaf = $settings['es_disable_waf_autodeploy'] ?? 'on')
            <option value="on" {{ $disableWaf === 'on' ? 'selected' : '' }}>on</option>
            <option value="off" {{ $disableWaf === 'off' ? 'selected' : '' }}>off</option>
          </select>
        </div>
        <div class="es-card-soft px-3 py-2">
          <label class="mb-1 block text-sm text-sky-100">Allow Crawler by UA (compat)</label>
          <select class="es-input" name="es_allow_ua_crawler_allowlist">
            @php($allowUa = $settings['es_allow_ua_crawler_allowlist'] ?? 'off')
            <option value="off" {{ $allowUa === 'off' ? 'selected' : '' }}>off (recommended)</option>
            <option value="on" {{ $allowUa === 'on' ? 'selected' : '' }}>on</option>
          </select>
        </div>
      </div>
      <div class="mt-3 grid gap-3 md:grid-cols-2">
        <div></div>
        <div>
          <label class="mb-1 block text-sm text-sky-100">ES Admin Rate Limit / min</label>
          <input class="es-input" type="number" min="10" max="600" name="es_admin_rate_limit_per_min" value="{{ $settings['es_admin_rate_limit_per_min'] ?? '60' }}">
        </div>
      </div>
      <div class="mt-3 grid gap-3 md:grid-cols-2">
        <div><label class="mb-1 block text-sm text-sky-100">Notes</label><input class="es-input" name="notes" value="{{ $settings['notes'] ?? '' }}" placeholder="Ops notes"></div>
        <div></div>
      </div>
      <div class="mt-3 grid gap-3 md:grid-cols-2">
        <div>
          <label class="mb-1 block text-sm text-sky-100">CF API Token</label>
          <input class="es-input" type="password" autocomplete="new-password" name="cf_api_token" value="" placeholder="{{ ($sensitiveConfigured['cf_api_token'] ?? false) ? 'Configured (leave blank to keep)' : '' }}">
        </div>
        <div><label class="mb-1 block text-sm text-sky-100">CF Account ID</label><input class="es-input" name="cf_account_id" value="{{ $settings['cf_account_id'] ?? '' }}" placeholder="8c610bded8021e624eb8abc24833d79a"></div>
      </div>
      <div class="mt-3 grid gap-3 md:grid-cols-2">
        <div>
          <label class="mb-1 block text-sm text-sky-100">OpenRouter API Key</label>
          <input class="es-input" type="password" autocomplete="new-password" name="openrouter_api_key" value="" placeholder="{{ ($sensitiveConfigured['openrouter_api_key'] ?? false) ? 'Configured (leave blank to keep)' : '' }}">
        </div>
        <div></div>
      </div>
      <div class="mt-3">
        <label class="mb-1 block text-sm text-sky-100">JWT Secret</label>
        <input class="es-input" type="password" autocomplete="new-password" name="jwt_secret" value="" placeholder="{{ ($sensitiveConfigured['jwt_secret'] ?? false) ? 'Configured (leave blank to keep)' : '' }}">
      </div>
      <button class="es-btn mt-4" type="submit">Save Settings</button>
    </form>
  </div>
@endsection
