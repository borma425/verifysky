@extends('layouts.customer-mirror')

@section('content')
  <div class="mb-4 flex items-center justify-between">
    <h1 class="es-title text-2xl">Firewall</h1>
    @if(!empty($firewallUsage))
      <div class="rounded-lg border border-sky-400/25 bg-slate-900/55 px-3 py-2 text-right text-xs text-sky-100">
        <div class="font-semibold">{{ $firewallUsage['plan_name'] ?? 'Plan' }}</div>
        <div class="es-muted">{{ $firewallUsage['used'] ?? 0 }} / {{ $firewallUsage['limit'] ?? 0 }} custom rules</div>
      </div>
    @endif
  </div>

  @if(!empty($loadErrors))
    <div class="mb-4 rounded-xl border border-amber-400/35 bg-amber-500/15 px-4 py-3 text-sm text-amber-100">
      @foreach($loadErrors as $msg)
        <div>{{ $msg }}</div>
      @endforeach
    </div>
  @endif

  <div class="es-card p-0">
    <div class="border-b border-white/10 p-5">
      <h2 class="text-lg font-bold text-white">Rules for all domains</h2>
      <p class="mt-1 text-sm text-sky-100/65">These rules are shown in read-only mode for the selected user.</p>
    </div>
    <div class="overflow-x-auto">
      <table class="es-table min-w-[1100px]">
        <thead>
        <tr>
          <th>Domain</th>
          <th>Description</th>
          <th>Action</th>
          <th>Field</th>
          <th>Value</th>
          <th>Status</th>
        </tr>
        </thead>
        <tbody>
        @forelse($firewallRules as $rule)
          <tr>
            <td>{{ $rule['domain_name'] }}</td>
            <td>{{ $rule['description_display'] }}</td>
            <td>{{ $rule['action'] }}</td>
            <td>{{ $rule['field'] }}</td>
            <td>{{ $rule['value_display'] }}</td>
            <td><span class="rounded-md border px-2 py-1 text-xs {{ $rule['status_class'] }}">{{ $rule['status_label'] }}</span></td>
          </tr>
        @empty
          <tr>
            <td colspan="6" class="py-8 text-center text-sky-100/70">No firewall rules exist for this client.</td>
          </tr>
        @endforelse
        </tbody>
      </table>
    </div>
  </div>
@endsection
