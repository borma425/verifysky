@extends('layouts.app')

@section('content')
  @php
    $domainName = $domain['domain_name'] ?? '';
    $zoneId = $domain['zone_id'] ?? '';
    $isForced = (int)($domain['force_captcha'] ?? 0) === 1;
  @endphp

  <div class="relative left-1/2 mb-4 w-[98vw] max-w-none -translate-x-1/2 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
    <div class="mb-3 flex flex-wrap items-center gap-2">
      <a href="{{ route('domains.index') }}" class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50">Back to Domains</a>
      <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700">{{ $domain['status'] ?? 'unknown' }}</span>
      <span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $isForced ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-700' }}">
        Force CAPTCHA: {{ $isForced ? 'Enabled' : 'Disabled' }}
      </span>
    </div>

    <h2 class="text-xl font-semibold">Rules Management: {{ $domainName }}</h2>
    <p class="mt-1 text-sm text-slate-500">Zone ID: <span class="font-mono text-slate-700">{{ $zoneId }}</span></p>

    @if(!empty($loadErrors))
      <div class="mt-3 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-800">
        @foreach($loadErrors as $msg)
          <div>{{ $msg }}</div>
        @endforeach
      </div>
    @endif
  </div>

  <div class="mb-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
    <h3 class="mb-3 text-lg font-semibold">Create Firewall Rule</h3>
    <form method="POST" action="{{ route('domains.rules.store', ['domain' => $domainName]) }}">
      @csrf
      <div class="grid gap-3 md:grid-cols-2">
        <div>
          <label class="mb-1 block text-sm text-slate-600">Action</label>
          <select name="action" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" required>
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
          <label class="mb-1 block text-sm text-slate-600">Description (optional)</label>
          <input type="text" name="description" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" placeholder="Example: Block abusive ASN">
        </div>
      </div>
      <div class="mt-3">
        <label class="mb-1 block text-sm text-slate-600">Expression</label>
        <textarea name="expression" rows="4" class="w-full rounded-lg border border-slate-300 px-3 py-2 font-mono text-xs" placeholder='Example: (ip.src in {1.2.3.4 5.6.7.8}) or (http.user_agent contains "python")' required></textarea>
      </div>
      <label class="mt-3 inline-flex items-center gap-2 text-sm text-slate-600">
        <input type="checkbox" name="paused" value="1" class="rounded border-slate-300">
        Create as paused
      </label>
      <div class="mt-4">
        <button type="submit" class="rounded-lg bg-sky-600 px-4 py-2 text-sm font-semibold text-white hover:bg-sky-500">Create Rule</button>
      </div>
    </form>
  </div>

  <div class="mb-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
    <h3 class="mb-3 text-lg font-semibold">Worker Routes</h3>
    <div class="overflow-x-auto">
      <table class="w-full min-w-[1200px] text-sm">
        <thead class="bg-slate-50 text-left text-slate-600">
          <tr>
            <th class="px-3 py-2">Pattern</th>
            <th class="px-3 py-2">Script</th>
            <th class="px-3 py-2">Route ID</th>
          </tr>
        </thead>
        <tbody>
        @forelse($workerRoutes as $route)
          <tr class="border-b border-slate-100">
            <td class="px-3 py-2 font-mono text-xs text-slate-700">{{ $route['pattern'] ?? '' }}</td>
            <td class="px-3 py-2">{{ $route['script'] ?? '' }}</td>
            <td class="px-3 py-2 font-mono text-xs text-slate-500">{{ $route['id'] ?? '' }}</td>
          </tr>
        @empty
          <tr><td colspan="3" class="px-3 py-3 text-slate-500">No worker routes found for this zone.</td></tr>
        @endforelse
        </tbody>
      </table>
    </div>
  </div>

  <div class="relative left-1/2 w-[98vw] max-w-none -translate-x-1/2 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
    <h3 class="mb-3 text-lg font-semibold">Firewall Rules</h3>
    <div class="overflow-x-auto">
      <table class="w-full min-w-[1350px] text-sm">
        <thead class="bg-slate-50 text-left text-slate-600">
          <tr>
            <th class="px-3 py-2">Description</th>
            <th class="px-3 py-2">Action</th>
            <th class="px-3 py-2">Status</th>
            <th class="px-3 py-2">Expression</th>
            <th class="px-3 py-2">Actions</th>
          </tr>
        </thead>
        <tbody>
        @forelse($firewallRules as $rule)
          @php $paused = (bool)($rule['paused'] ?? false); @endphp
          <tr class="border-b border-slate-100 align-top">
            <td class="px-3 py-2">
              <div class="font-medium text-slate-800">{{ $rule['description'] ?? 'No description' }}</div>
              <div class="font-mono text-xs text-slate-500">{{ $rule['id'] ?? '' }}</div>
            </td>
            <td class="px-3 py-2">
              <span class="rounded-full bg-indigo-100 px-2.5 py-1 text-xs font-semibold text-indigo-700">{{ $rule['action'] ?? '' }}</span>
            </td>
            <td class="px-3 py-2">
              <span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $paused ? 'bg-amber-100 text-amber-700' : 'bg-emerald-100 text-emerald-700' }}">
                {{ $paused ? 'Paused' : 'Enabled' }}
              </span>
            </td>
            <td class="px-3 py-2 font-mono text-xs text-slate-700">{{ $rule['filter']['expression'] ?? '' }}</td>
            <td class="px-3 py-2">
              <div class="flex flex-wrap gap-2">
                <form method="POST" action="{{ route('domains.rules.toggle', ['domain' => $domainName, 'ruleId' => $rule['id'] ?? '']) }}">
                  @csrf
                  <input type="hidden" name="paused" value="{{ $paused ? 0 : 1 }}">
                  <button type="submit" class="rounded-lg {{ $paused ? 'bg-emerald-600 hover:bg-emerald-500' : 'bg-amber-600 hover:bg-amber-500' }} px-3 py-1.5 text-xs font-semibold text-white">
                    {{ $paused ? 'Enable' : 'Pause' }}
                  </button>
                </form>
                <form method="POST" action="{{ route('domains.rules.destroy', ['domain' => $domainName, 'ruleId' => $rule['id'] ?? '']) }}">
                  @csrf
                  @method('DELETE')
                  <button type="submit" class="rounded-lg bg-rose-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-rose-500">Delete</button>
                </form>
              </div>
            </td>
          </tr>
        @empty
          <tr><td colspan="5" class="px-3 py-3 text-slate-500">No firewall rules found for this zone.</td></tr>
        @endforelse
        </tbody>
      </table>
    </div>
  </div>
@endsection
