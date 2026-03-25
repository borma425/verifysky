@extends('layouts.app')

@section('content')
  <div class="es-card es-animate mb-4 p-5 md:p-6">
    <h2 class="es-title mb-3">Domains</h2>
    <p class="es-subtitle mb-3">Add only the domain and the dashboard will auto-fetch Zone ID and create Turnstile keys from Cloudflare. Advanced fields below are optional overrides.</p>
    @if($error)<div class="mb-3 rounded-xl border border-rose-400/30 bg-rose-500/15 px-4 py-3 text-sm text-rose-200">{{ $error }}</div>@endif
    <form method="POST" action="{{ route('domains.store') }}">
      @csrf
      <div class="grid gap-3 md:grid-cols-3">
        <div><label class="mb-1 block text-sm text-sky-100">Domain</label><input class="es-input" name="domain_name" placeholder="example.com" required></div>
        <div><label class="mb-1 block text-sm text-sky-100">Zone ID (optional)</label><input class="es-input" name="zone_id" placeholder="Auto if empty"></div>
        <div>
          <label class="mb-1 block text-sm text-sky-100">Security Mode</label>
          <select class="es-input" name="security_mode">
            <option value="balanced">Balanced (Recommended)</option>
            <option value="monitor">Monitor</option>
            <option value="aggressive">Aggressive</option>
          </select>
        </div>
      </div>
      <div class="mt-3 grid gap-3 md:grid-cols-2">
        <div><label class="mb-1 block text-sm text-sky-100">Turnstile Site Key (optional)</label><input class="es-input" name="turnstile_sitekey" placeholder="Auto if empty"></div>
        <div><label class="mb-1 block text-sm text-sky-100">Turnstile Secret (optional)</label><input class="es-input" name="turnstile_secret" placeholder="Auto if empty"></div>
      </div>
      <button class="es-btn mt-4" type="submit">Add / Update Domain</button>
    </form>
  </div>

  <div class="es-card es-animate es-animate-delay relative left-1/2 w-[98vw] max-w-none -translate-x-1/2 p-5 md:p-6">
    <div class="overflow-x-auto">
    <table class="es-table min-w-[1450px]">
      <thead><tr><th>Domain</th><th>Zone ID</th><th>Status</th><th>Security Mode</th><th>Force CAPTCHA</th><th>Created</th><th>Actions</th></tr></thead>
      <tbody>
      @forelse($domains as $d)
        @php $forced = (int)($d['force_captcha'] ?? 0) === 1; @endphp
        @php $mode = strtolower((string)($d['security_mode'] ?? 'balanced')); @endphp
        @php $status = strtolower((string)($d['status'] ?? '')); @endphp
        <tr>
          <td class="whitespace-nowrap">{{ $d['domain_name'] ?? '' }}</td>
          <td class="whitespace-nowrap">{{ $d['zone_id'] ?? '' }}</td>
          <td class="whitespace-nowrap">{{ $d['status'] ?? '' }}</td>
          <td>
            <span class="es-chip {{ $mode === 'aggressive' ? 'border-rose-400/35 bg-rose-500/20 text-rose-200' : ($mode === 'monitor' ? 'border-amber-400/35 bg-amber-500/20 text-amber-100' : 'border-sky-400/35 bg-sky-500/20 text-sky-100') }}">
              {{ ucfirst($mode) }}
            </span>
          </td>
          <td>
            <span class="es-chip {{ $forced ? 'border-emerald-400/35 bg-emerald-500/20 text-emerald-100' : '' }}">
              {{ $forced ? 'Enabled' : 'Disabled' }}
            </span>
          </td>
          <td class="whitespace-nowrap">{{ $d['created_at'] ?? '' }}</td>
          <td>
            <div class="flex flex-wrap items-center gap-2">
            <form method="POST" action="{{ route('domains.sync_route', ['domain' => $d['domain_name']]) }}">
              @csrf
              <button class="es-btn" type="submit">
                Sync Route
              </button>
            </form>
            <form method="POST" action="{{ route('domains.force_captcha', ['domain' => $d['domain_name']]) }}">
              @csrf
              <input type="hidden" name="force_captcha" value="{{ $forced ? 0 : 1 }}">
              <button class="es-btn {{ $forced ? 'es-btn-secondary' : '' }}" type="submit">
                {{ $forced ? 'Disable Forced CAPTCHA' : 'Enable Forced CAPTCHA' }}
              </button>
            </form>
            @if($status === 'active')
              <form method="POST" action="{{ route('domains.status', ['domain' => $d['domain_name']]) }}">
                @csrf
                <input type="hidden" name="status" value="paused">
                <button class="es-btn es-btn-warning" type="submit">Pause</button>
              </form>
            @else
              <form method="POST" action="{{ route('domains.status', ['domain' => $d['domain_name']]) }}">
                @csrf
                <input type="hidden" name="status" value="active">
                <button class="es-btn es-btn-success" type="submit">Activate</button>
              </form>
            @endif
            <form method="POST" action="{{ route('domains.destroy', ['domain' => $d['domain_name']]) }}">
              @csrf
              @method('DELETE')
              <button class="es-btn es-btn-danger" type="submit">Delete</button>
            </form>
            </div>

            <hr class="my-3 border-white/10">

            <div class="es-card-soft p-3">
              <div class="mb-2 text-xs font-semibold text-sky-100">Security Mode (Domain Policy)</div>
              <p class="mb-2 text-xs es-muted">
                <span class="font-semibold">Monitor:</span> logs and challenges suspicious traffic with minimal hard-blocks.
                <span class="font-semibold ml-1">Balanced:</span> default and recommended for daily protection.
                <span class="font-semibold ml-1">Aggressive:</span> faster challenge/block during active attacks.
              </p>
              <form method="POST" action="{{ route('domains.security_mode', ['domain' => $d['domain_name']]) }}" class="flex flex-wrap items-center gap-2">
                @csrf
                <select name="security_mode" class="es-input text-xs font-semibold">
                  <option value="balanced" @selected($mode === 'balanced')>Balanced (Recommended)</option>
                  <option value="monitor" @selected($mode === 'monitor')>Monitor</option>
                  <option value="aggressive" @selected($mode === 'aggressive')>Aggressive</option>
                </select>
                <button class="es-btn es-btn-secondary" type="submit">
                  Apply Mode
                </button>
              </form>
            </div>
          </td>
        </tr>
      @empty
        <tr><td colspan="7" class="text-slate-300">No domains found.</td></tr>
      @endforelse
      </tbody>
    </table>
    </div>
  </div>
@endsection
