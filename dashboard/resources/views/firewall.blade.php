@extends('layouts.app')

@section('content')
  <div class="mb-4 flex items-center justify-between">
    <h2 class="es-title text-2xl">Global Firewall</h2>
    @if(!empty($firewallUsage))
      <div class="rounded-lg border border-sky-400/25 bg-slate-900/55 px-3 py-2 text-right text-xs text-sky-100">
        <div class="font-semibold">{{ $firewallUsage['plan_name'] ?? 'Plan' }}</div>
        @if(($firewallUsage['limit'] ?? null) !== null)
          <div class="es-muted">{{ $firewallUsage['used'] ?? 0 }} / {{ $firewallUsage['limit'] ?? 0 }} custom rules</div>
        @else
          <div class="es-muted">Unlimited custom rules</div>
        @endif
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

  @include('firewall.partials.create-form')

  <form id="bulkDeleteForm" method="POST" action="{{ route('firewall.bulk_destroy') }}">
    @csrf
    @method('DELETE')

    @include('firewall.partials.ai-rules-table')
    @include('firewall.partials.manual-rules-table')
  </form>

  @include('firewall.partials.toggle-forms')
@endsection
