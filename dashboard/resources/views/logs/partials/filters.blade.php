@php
  $logsIndexRoute = $logsIndexRoute ?? route('logs.index');
  $logsResetRoute = $logsResetRoute ?? $logsIndexRoute;
  $logsClearRoute = $logsClearRoute ?? route('logs.clear');
@endphp

<div class="es-card es-animate mb-4 p-5 md:p-6">
  <div class="flex flex-col md:flex-row md:items-center justify-between mb-4 border-b border-sky-500/20 pb-4">
    <div>
      <h2 class="es-title m-0">{{ $isTenantScoped ? 'Security Analytics' : 'Security Logs' }}</h2>
      <p class="mt-2 max-w-3xl text-sm text-sky-100/70">
        {{ $isTenantScoped ? 'This view is scoped to the domains assigned to your tenant so you can verify blocked traffic and legitimate visitors safely.' : 'Inspect recent security events, filter by domain or IP, and manage enforcement actions.' }}
      </p>
    </div>
    @if($canManageLogActions)
      <form method="POST" action="{{ $logsClearRoute }}" class="mt-3 md:mt-0 flex items-center justify-end gap-2" onsubmit="return confirm('Are you sure you want to clear these logs? This cannot be undone.');">
        @csrf
        <select name="period" class="es-input text-xs py-1.5 px-2 h-auto w-auto bg-gray-900 border-gray-700 text-gray-300 focus:ring-rose-500">
          <option value="7d">Older than 7 days</option>
          <option value="30d">Older than 30 days</option>
          <option value="all">All Logs (Reset)</option>
        </select>
        <button type="submit" class="es-btn px-3 py-1.5 text-xs bg-rose-600 hover:bg-rose-500 border-rose-600 flex items-center gap-1.5 text-white/90">
          <svg class="w-3.5 h-3.5 opacity-80" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
          Clear
        </button>
      </form>
    @endif
  </div>

  <form method="GET" action="{{ $logsIndexRoute }}" class="flex flex-col gap-3 md:flex-row md:items-end md:flex-wrap">
    <div class="md:w-72">
      <label class="mb-1 block text-sm text-sky-100">Filter by domain</label>
      <select class="es-input" name="domain_name">
        <option value="">All domains</option>
        @foreach(($domainOptions ?? []) as $optionDomain)
          <option value="{{ $optionDomain }}" @selected(($domainName ?? '') === $optionDomain)>{{ $optionDomain }}</option>
        @endforeach
      </select>
    </div>
    <div class="md:w-72">
      <label class="mb-1 block text-sm text-sky-100">Filter by event type</label>
      <select class="es-input" name="event_type">
        <option value="">All events</option>
        @foreach(($eventTypeOptions ?? []) as $optionEvent)
          <option value="{{ $optionEvent }}" @selected(($eventType ?? '') === $optionEvent)>{{ $eventLabels[$optionEvent] ?? $optionEvent }}</option>
        @endforeach
      </select>
    </div>
    <div class="md:w-72">
      <label class="mb-1 block text-sm text-sky-100">Filter by IP</label>
      <input class="es-input" name="ip_address" value="{{ $ipAddress ?? '' }}" placeholder="e.g. 203.0.113.10">
    </div>
    <button class="es-btn" type="submit">Filter</button>
    <a class="es-btn es-btn-secondary" href="{{ $logsResetRoute }}">Reset</a>
  </form>

  @if($error)
    <div class="mt-3 rounded-xl border border-rose-400/30 bg-rose-500/15 px-4 py-3 text-sm text-rose-200">{{ $error }}</div>
  @endif
</div>
