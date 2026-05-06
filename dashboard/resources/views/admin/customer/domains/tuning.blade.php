@extends('layouts.customer-mirror')

@section('content')
  <div class="mb-4">
    <a href="{{ route('admin.tenants.customer.domains.index', $tenant) }}" class="es-btn es-btn-secondary text-sm">&larr; Back to Domains</a>
  </div>

  <div class="es-card p-5 md:p-6">
    <h1 class="text-2xl font-bold text-white">Protection settings for {{ $domain }}</h1>
    <p class="mt-2 text-sm text-sky-100/70">Read-only view for this user's domain settings.</p>

    <div class="mt-5 grid gap-4 md:grid-cols-2">
      <div class="rounded-xl border border-white/10 bg-[#202632] p-4">
        <div class="text-[10px] uppercase tracking-[0.18em] text-[#76859C]">Server</div>
        <div class="mt-2 text-lg font-semibold text-white">{{ $originServer ?: 'Not configured' }}</div>
      </div>
      <div class="rounded-xl border border-white/10 bg-[#202632] p-4">
        <div class="text-[10px] uppercase tracking-[0.18em] text-[#76859C]">Protection level</div>
        <div class="mt-2 text-lg font-semibold text-white">{{ ucfirst($activeChallengeMode) }}</div>
      </div>
    </div>

    <div class="mt-5 grid gap-4 lg:grid-cols-2">
      <div class="rounded-xl border border-white/10 bg-[#202632] p-4">
        <h2 class="text-lg font-bold text-white">Security settings</h2>
        <div class="mt-3 space-y-2 text-sm text-sky-100/80">
          @forelse($thresholds as $key => $value)
            <div class="flex items-center justify-between gap-4 border-b border-white/5 pb-2">
              <span>{{ str_replace('_', ' ', (string) $key) }}</span>
              <span class="font-semibold text-white">{{ is_array($value) ? json_encode($value) : $value }}</span>
            </div>
          @empty
            <div>No custom security settings configured.</div>
          @endforelse
        </div>
      </div>
      <div class="rounded-xl border border-white/10 bg-[#202632] p-4">
        <h2 class="text-lg font-bold text-white">Challenge settings</h2>
        <div class="mt-3 space-y-3">
          @foreach($challengeProfiles as $profile => $profileValues)
            <div class="rounded-lg border border-white/8 bg-[#171C26] p-3">
              <div class="font-semibold text-white">{{ ucfirst((string) $profile) }}</div>
              <div class="mt-2 text-sm text-sky-100/75">Solve: {{ $profileValues['solve'] }} ms</div>
              <div class="text-sm text-sky-100/75">Points: {{ $profileValues['points'] }}</div>
              <div class="text-sm text-sky-100/75">Tolerance: {{ $profileValues['tolerance'] }}</div>
            </div>
          @endforeach
        </div>
      </div>
    </div>
  </div>
@endsection
