@extends('layouts.admin')

@section('content')
  <div class="mb-6">
    <h1 class="es-title">Admin Command Center</h1>
    <p class="es-subtitle mt-2">Platform operations, tenant management, and security telemetry.</p>
  </div>

  <div class="grid gap-4 md:grid-cols-4">
    <div class="es-card p-5">
      <div class="text-xs font-bold uppercase tracking-[0.18em] text-[#7F8BA0]">Tenants</div>
      <div class="mt-3 text-3xl font-extrabold text-white">{{ number_format($stats['tenants'] ?? 0) }}</div>
    </div>
    <div class="es-card p-5">
      <div class="text-xs font-bold uppercase tracking-[0.18em] text-[#7F8BA0]">Domains</div>
      <div class="mt-3 text-3xl font-extrabold text-white">{{ number_format($stats['domains'] ?? 0) }}</div>
    </div>
    <div class="es-card p-5">
      <div class="text-xs font-bold uppercase tracking-[0.18em] text-[#7F8BA0]">Active Grants</div>
      <div class="mt-3 text-3xl font-extrabold text-white">{{ $stats['active_grants'] === null ? 'N/A' : number_format($stats['active_grants']) }}</div>
    </div>
    <div class="es-card p-5">
      <div class="text-xs font-bold uppercase tracking-[0.18em] text-[#7F8BA0]">Active Subscriptions</div>
      <div class="mt-3 text-3xl font-extrabold text-white">{{ $stats['active_subscriptions'] === null ? 'N/A' : number_format($stats['active_subscriptions']) }}</div>
    </div>
  </div>

  <div class="mt-6 grid gap-4 md:grid-cols-3">
    <a href="{{ route('admin.tenants.index') }}" class="es-card block p-5 hover:border-cyan-300/35">
      <div class="text-lg font-bold text-white">Tenant Operations</div>
      <p class="mt-2 text-sm text-sky-100/70">Open billing, grants, subscriptions, usage cycles, memberships, and domains.</p>
    </a>
    <a href="{{ route('admin.logs.security') }}" class="es-card block p-5 hover:border-cyan-300/35">
      <div class="text-lg font-bold text-white">Security Logs</div>
      <p class="mt-2 text-sm text-sky-100/70">Inspect D1 events across all protected domains.</p>
    </a>
    <a href="{{ route('admin.settings.index') }}" class="es-card block p-5 hover:border-cyan-300/35">
      <div class="text-lg font-bold text-white">Platform Settings</div>
      <p class="mt-2 text-sm text-sky-100/70">Manage runtime values and Cloudflare synchronization settings.</p>
    </a>
  </div>
@endsection
