@extends('layouts.app')

@section('content')
  <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
    <h2 class="mb-2 text-xl font-semibold">Settings & Secrets</h2>
    <p class="mb-4 text-sm text-slate-500">Use this page to keep operational values for your team. For production-grade secret hygiene, move secrets to vault/KMS.</p>
    <form method="POST" action="{{ route('settings.update') }}">
      @csrf
      <div class="grid gap-3 md:grid-cols-2">
        <div><label class="mb-1 block text-sm text-slate-600">OpenRouter Model</label><input class="w-full rounded-lg border border-slate-300 px-3 py-2" name="openrouter_model" value="{{ $settings['openrouter_model'] ?? '' }}" placeholder="qwen/qwen3-next-80b-a3b-instruct:free"></div>
        <div><label class="mb-1 block text-sm text-slate-600">OpenRouter Fallback Models</label><input class="w-full rounded-lg border border-slate-300 px-3 py-2" name="openrouter_fallback_models" value="{{ $settings['openrouter_fallback_models'] ?? '' }}" placeholder="openai/gpt-oss-120b:free,nvidia/nemotron-3-super:free"></div>
      </div>
      <div class="mt-3 grid gap-3 md:grid-cols-2">
        <div><label class="mb-1 block text-sm text-slate-600">Worker Script Name</label><input class="w-full rounded-lg border border-slate-300 px-3 py-2" name="worker_script_name" value="{{ $settings['worker_script_name'] ?? 'edge-shield' }}" placeholder="edge-shield"></div>
        <div><label class="mb-1 block text-sm text-slate-600">ES Admin Token</label><input class="w-full rounded-lg border border-slate-300 px-3 py-2" name="es_admin_token" value="{{ $settings['es_admin_token'] ?? '' }}" placeholder="token used for /es-admin/* endpoints"></div>
      </div>
      <div class="mt-3 grid gap-3 md:grid-cols-2">
        <div class="rounded-lg border border-slate-200 px-3 py-2">
          <label class="mb-1 block text-sm text-slate-600">Disable WAF Auto-Deploy</label>
          <select class="w-full rounded-lg border border-slate-300 px-3 py-2" name="es_disable_waf_autodeploy">
            @php($disableWaf = $settings['es_disable_waf_autodeploy'] ?? 'on')
            <option value="on" {{ $disableWaf === 'on' ? 'selected' : '' }}>on</option>
            <option value="off" {{ $disableWaf === 'off' ? 'selected' : '' }}>off</option>
          </select>
        </div>
        <div class="rounded-lg border border-slate-200 px-3 py-2">
          <label class="mb-1 block text-sm text-slate-600">Allow Crawler by UA (compat)</label>
          <select class="w-full rounded-lg border border-slate-300 px-3 py-2" name="es_allow_ua_crawler_allowlist">
            @php($allowUa = $settings['es_allow_ua_crawler_allowlist'] ?? 'off')
            <option value="off" {{ $allowUa === 'off' ? 'selected' : '' }}>off (recommended)</option>
            <option value="on" {{ $allowUa === 'on' ? 'selected' : '' }}>on</option>
          </select>
        </div>
      </div>
      <div class="mt-3 grid gap-3 md:grid-cols-2">
        <div><label class="mb-1 block text-sm text-slate-600">Notes</label><input class="w-full rounded-lg border border-slate-300 px-3 py-2" name="notes" value="{{ $settings['notes'] ?? '' }}" placeholder="Ops notes"></div>
        <div></div>
      </div>
      <div class="mt-3 grid gap-3 md:grid-cols-2">
        <div><label class="mb-1 block text-sm text-slate-600">CF API Token</label><input class="w-full rounded-lg border border-slate-300 px-3 py-2" name="cf_api_token" value="{{ $settings['cf_api_token'] ?? '' }}"></div>
        <div><label class="mb-1 block text-sm text-slate-600">CF Account ID</label><input class="w-full rounded-lg border border-slate-300 px-3 py-2" name="cf_account_id" value="{{ $settings['cf_account_id'] ?? '' }}" placeholder="8c610bded8021e624eb8abc24833d79a"></div>
      </div>
      <div class="mt-3 grid gap-3 md:grid-cols-2">
        <div><label class="mb-1 block text-sm text-slate-600">OpenRouter API Key</label><input class="w-full rounded-lg border border-slate-300 px-3 py-2" name="openrouter_api_key" value="{{ $settings['openrouter_api_key'] ?? '' }}"></div>
        <div></div>
      </div>
      <div class="mt-3">
        <label class="mb-1 block text-sm text-slate-600">JWT Secret</label>
        <input class="w-full rounded-lg border border-slate-300 px-3 py-2" name="jwt_secret" value="{{ $settings['jwt_secret'] ?? '' }}">
      </div>
      <button class="mt-4 rounded-lg bg-sky-500 px-4 py-2 text-sm font-semibold text-white hover:bg-sky-400" type="submit">Save Settings</button>
    </form>
  </div>
@endsection
