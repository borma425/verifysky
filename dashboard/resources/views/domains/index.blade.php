@extends('layouts.app')

@section('content')
  <div class="mb-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
    <h2 class="mb-3 text-xl font-semibold">Domains</h2>
    <p class="mb-3 text-sm text-slate-500">Add only the domain and the dashboard will auto-fetch Zone ID and create Turnstile keys from Cloudflare. Advanced fields below are optional overrides.</p>
    @if($error)<div class="mb-3 rounded-xl border border-rose-300 bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ $error }}</div>@endif
    <form method="POST" action="{{ route('domains.store') }}">
      @csrf
      <div class="grid gap-3 md:grid-cols-3">
        <div><label class="mb-1 block text-sm text-slate-600">Domain</label><input class="w-full rounded-lg border border-slate-300 px-3 py-2" name="domain_name" placeholder="example.com" required></div>
        <div><label class="mb-1 block text-sm text-slate-600">Zone ID (optional)</label><input class="w-full rounded-lg border border-slate-300 px-3 py-2" name="zone_id" placeholder="Auto if empty"></div>
        <div>
          <label class="mb-1 block text-sm text-slate-600">Security Mode</label>
          <select class="w-full rounded-lg border border-slate-300 px-3 py-2" name="security_mode">
            <option value="balanced">Balanced (Recommended)</option>
            <option value="monitor">Monitor</option>
            <option value="aggressive">Aggressive</option>
          </select>
        </div>
      </div>
      <div class="mt-3 grid gap-3 md:grid-cols-2">
        <div><label class="mb-1 block text-sm text-slate-600">Turnstile Site Key (optional)</label><input class="w-full rounded-lg border border-slate-300 px-3 py-2" name="turnstile_sitekey" placeholder="Auto if empty"></div>
        <div><label class="mb-1 block text-sm text-slate-600">Turnstile Secret (optional)</label><input class="w-full rounded-lg border border-slate-300 px-3 py-2" name="turnstile_secret" placeholder="Auto if empty"></div>
      </div>
      <button class="mt-4 rounded-lg bg-sky-500 px-4 py-2 text-sm font-semibold text-white hover:bg-sky-400" type="submit">Add / Update Domain</button>
    </form>
  </div>

  <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
    <div class="overflow-x-auto">
    <table class="min-w-full text-sm">
      <thead class="bg-slate-50 text-left text-slate-600"><tr><th class="px-3 py-2">Domain</th><th class="px-3 py-2">Zone ID</th><th class="px-3 py-2">Status</th><th class="px-3 py-2">Security Mode</th><th class="px-3 py-2">Force CAPTCHA</th><th class="px-3 py-2">Created</th><th class="px-3 py-2">Actions</th></tr></thead>
      <tbody>
      @forelse($domains as $d)
        @php $forced = (int)($d['force_captcha'] ?? 0) === 1; @endphp
        @php $mode = strtolower((string)($d['security_mode'] ?? 'balanced')); @endphp
        @php $status = strtolower((string)($d['status'] ?? '')); @endphp
        <tr class="border-b border-slate-100">
          <td class="px-3 py-2">{{ $d['domain_name'] ?? '' }}</td>
          <td class="px-3 py-2">{{ $d['zone_id'] ?? '' }}</td>
          <td class="px-3 py-2">{{ $d['status'] ?? '' }}</td>
          <td class="px-3 py-2">
            <span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $mode === 'aggressive' ? 'bg-rose-100 text-rose-700' : ($mode === 'monitor' ? 'bg-amber-100 text-amber-700' : 'bg-sky-100 text-sky-700') }}">
              {{ ucfirst($mode) }}
            </span>
          </td>
          <td class="px-3 py-2">
            <span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $forced ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-700' }}">
              {{ $forced ? 'Enabled' : 'Disabled' }}
            </span>
          </td>
          <td class="px-3 py-2">{{ $d['created_at'] ?? '' }}</td>
          <td class="px-3 py-2">
            <div class="flex flex-wrap items-center gap-2">
            <a href="{{ route('domains.rules', ['domain' => $d['domain_name']]) }}" class="rounded-lg bg-violet-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-violet-500">
              Rules
            </a>
            <form method="POST" action="{{ route('domains.sync_route', ['domain' => $d['domain_name']]) }}">
              @csrf
              <button class="rounded-lg bg-sky-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-sky-500" type="submit">
                Sync Route
              </button>
            </form>
            <form method="POST" action="{{ route('domains.force_captcha', ['domain' => $d['domain_name']]) }}">
              @csrf
              <input type="hidden" name="force_captcha" value="{{ $forced ? 0 : 1 }}">
              <button class="rounded-lg {{ $forced ? 'bg-slate-700 hover:bg-slate-600' : 'bg-indigo-600 hover:bg-indigo-500' }} px-3 py-1.5 text-xs font-semibold text-white" type="submit">
                {{ $forced ? 'Disable Forced CAPTCHA' : 'Enable Forced CAPTCHA' }}
              </button>
            </form>
            @if($status === 'active')
              <form method="POST" action="{{ route('domains.status', ['domain' => $d['domain_name']]) }}">
                @csrf
                <input type="hidden" name="status" value="paused">
                <button class="rounded-lg bg-amber-500 px-3 py-1.5 text-xs font-semibold text-white hover:bg-amber-400" type="submit">Pause</button>
              </form>
            @else
              <form method="POST" action="{{ route('domains.status', ['domain' => $d['domain_name']]) }}">
                @csrf
                <input type="hidden" name="status" value="active">
                <button class="rounded-lg bg-emerald-500 px-3 py-1.5 text-xs font-semibold text-white hover:bg-emerald-400" type="submit">Activate</button>
              </form>
            @endif
            <form method="POST" action="{{ route('domains.destroy', ['domain' => $d['domain_name']]) }}">
              @csrf
              @method('DELETE')
              <button class="rounded-lg bg-rose-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-rose-500" type="submit">Delete</button>
            </form>
            </div>

            <hr class="my-3 border-slate-200">

            <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
              <div class="mb-2 text-xs font-semibold text-slate-700">Security Mode (Domain Policy)</div>
              <p class="mb-2 text-xs text-slate-600">
                <span class="font-semibold">Monitor:</span> logs and challenges suspicious traffic with minimal hard-blocks.
                <span class="font-semibold ml-1">Balanced:</span> default and recommended for daily protection.
                <span class="font-semibold ml-1">Aggressive:</span> faster challenge/block during active attacks.
              </p>
              <form method="POST" action="{{ route('domains.security_mode', ['domain' => $d['domain_name']]) }}" class="flex flex-wrap items-center gap-2">
                @csrf
                <select name="security_mode" class="rounded-lg border border-slate-300 px-2 py-1.5 text-xs font-semibold text-slate-700">
                  <option value="balanced" @selected($mode === 'balanced')>Balanced (Recommended)</option>
                  <option value="monitor" @selected($mode === 'monitor')>Monitor</option>
                  <option value="aggressive" @selected($mode === 'aggressive')>Aggressive</option>
                </select>
                <button class="rounded-lg bg-slate-800 px-3 py-1.5 text-xs font-semibold text-white hover:bg-slate-700" type="submit">
                  Apply Mode
                </button>
              </form>
            </div>
          </td>
        </tr>
      @empty
        <tr><td colspan="7" class="px-3 py-3 text-slate-500">No domains found.</td></tr>
      @endforelse
      </tbody>
    </table>
    </div>
  </div>
@endsection
