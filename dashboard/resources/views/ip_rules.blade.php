@extends('layouts.app')

@section('content')
  <div class="mb-4 flex items-center justify-between">
    <h2 class="es-title text-2xl">Global IP Rules Builder</h2>
  </div>

  <div class="mb-5 grid gap-5 md:grid-cols-2">
    <div class="es-card p-5 md:p-6">
      <h3 class="es-subtitle mb-4 text-lg">Add New Rule</h3>
      @if(session('status'))<div class="mb-3 rounded-xl border border-emerald-400/30 bg-emerald-500/15 px-4 py-3 text-sm text-emerald-200">{{ session('status') }}</div>@endif
      @if(session('error'))<div class="mb-3 rounded-xl border border-rose-400/30 bg-rose-500/15 px-4 py-3 text-sm text-rose-200">{{ session('error') }}</div>@endif
      
      @if(empty($domains))
        <div class="mb-3 rounded-xl border border-amber-400/30 bg-amber-500/15 px-4 py-3 text-sm text-amber-200">
          You need to add at least one domain before creating IP rules.
        </div>
      @else
        <form method="POST" action="{{ route('ip_rules.store') }}">
          @csrf
          
          <div class="mb-4">
            <label class="mb-1 block text-sm text-sky-100">Domain</label>
            <select class="es-input" name="domain_name" required>
              <option value="" disabled selected>Select a domain...</option>
              @foreach($domains as $d)
                <option value="{{ $d['domain_name'] }}">{{ $d['domain_name'] }}</option>
              @endforeach
            </select>
          </div>

          <div class="mb-4">
            <label class="mb-1 block text-sm text-sky-100">Action</label>
            <select class="es-input" name="action" required>
              <option value="block">Block</option>
              <option value="allow">Allow (Fast-Pass)</option>
            </select>
          </div>
          
          <div class="mb-4">
            <label class="mb-1 block text-sm text-sky-100">IP, CIDR, or ASN</label>
            <input class="es-input" name="ip_or_cidr" placeholder="e.g. 192.168.1.10, 10.0.0.0/24, or AS12345" required>
          </div>
          
          <div class="mb-4">
            <label class="mb-1 block text-sm text-sky-100">Note / Reason (Optional)</label>
            <input class="es-input" name="note" placeholder="Why was this rule added?">
          </div>
          
          <button class="es-btn w-full" type="submit">Add IP Rule</button>
        </form>
      @endif
    </div>

    <div class="es-card relative overflow-hidden p-5 md:p-6">
      <div class="absolute -right-10 -top-10 h-40 w-40 rounded-full bg-sky-500/10 blur-3xl"></div>
      <h3 class="es-subtitle mb-4 relative text-lg z-10">How this works</h3>
      <div class="relative z-10 text-sm es-muted space-y-3">
        <p><strong>Custom IP Rules</strong> are evaluated directly inside the Cloudflare Worker at the edge, before any risk scoring occurs.</p>
        <p>This completely bypasses the Cloudflare Dashboard WAF rules, ensuring that free-tier limits (usually 5 rules max) do not restrict your ability to block or allow large sets of networks.</p>
        <ul class="list-inside list-disc opacity-80">
          <li><strong>Block:</strong> Immediately returns a 403 Forbidden.</li>
          <li><strong>Allow:</strong> Completely bypasses the CAPTCHA and risk checks.</li>
          <li>Supports exact IPv4/IPv6, standard IPv4 CIDR blocks (e.g., <code>192.168.1.0/24</code>), and ASN blocks (e.g. <code>AS12345</code>).</li>
        </ul>
      </div>
    </div>
  </div>

  <div class="es-card p-0">
    @foreach ($loadErrors as $err)
      <div class="my-4 mx-4 rounded-xl border border-rose-400/30 bg-rose-500/15 px-4 py-3 text-sm text-rose-200">{{ $err }}</div>
    @endforeach

    <div class="overflow-x-auto">
      <table class="es-table min-w-full">
        <thead>
          <tr>
            <th>Domain</th>
            <th>Action</th>
            <th>Target (IP/CIDR/ASN)</th>
            <th>Note</th>
            <th>Created At</th>
            <th>Remove</th>
          </tr>
        </thead>
        <tbody>
          @forelse($ipRules as $rule)
            @php $isAllow = ($rule['action'] ?? 'block') === 'allow'; @endphp
            <tr>
              <td class="font-semibold text-sky-100">{{ $rule['domain_name'] ?? '' }}</td>
              <td>
                <span class="es-chip {{ $isAllow ? 'border-emerald-400/35 bg-emerald-500/20 text-emerald-100' : 'border-rose-400/35 bg-rose-500/20 text-rose-200' }}">
                  {{ strtoupper($rule['action'] ?? '') }}
                </span>
              </td>
              <td class="whitespace-nowrap font-mono">{{ $rule['ip_or_cidr'] ?? '' }}</td>
              <td>{{ $rule['note'] ?? '' }}</td>
              <td class="whitespace-nowrap text-sm">{{ $rule['created_at'] ?? '' }}</td>
              <td>
                <form method="POST" action="{{ route('ip_rules.destroy', ['ruleId' => $rule['id']]) }}">
                  @csrf
                  @method('DELETE')
                  <input type="hidden" name="domain_name" value="{{ $rule['domain_name'] }}">
                  <button class="es-btn es-btn-danger text-xs px-3 py-1.5" type="submit">Delete</button>
                </form>
              </td>
            </tr>
          @empty
            <tr>
              <td colspan="6" class="py-8 text-center text-slate-400">No IP rules found across any domain.</td>
            </tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </div>
@endsection
