@php
  $logsIndexRoute = $logsIndexRoute ?? route('logs.index');
  $logsResetRoute = $logsResetRoute ?? $logsIndexRoute;
  $logsClearRoute = $logsClearRoute ?? route('logs.clear');
@endphp

<section class="vs-logs-panel vs-logs-command es-animate" aria-labelledby="logs-page-title">
  <div class="vs-logs-command-head">
    <div class="vs-logs-title-block">
      <div class="vs-logs-title-row">
        <h2 id="logs-page-title">{{ $isTenantScoped ? 'Security Activity' : 'Security Logs' }}</h2>
        @if(!empty($edgeShieldTargetLabel))
          <span class="vs-logs-env-badge {{ ($edgeShieldMutationsAllowed ?? false) ? 'vs-logs-env-live' : 'vs-logs-env-locked' }}">
            {{ $edgeShieldTargetLabel }}
          </span>
        @endif
      </div>
      <p>
        {{ $isTenantScoped ? 'See blocked traffic and real visitors for your domains.' : 'See recent security events, filter by domain or IP, and manage actions.' }}
      </p>
    </div>

    @if($canManageLogActions)
      <form method="POST" action="{{ $logsClearRoute }}" class="vs-logs-clear-form" onsubmit="return confirm('Are you sure you want to clear these logs? This cannot be undone.');">
        @csrf
        <label class="vs-logs-sr" for="logs-clear-period">Clear logs period</label>
        <select id="logs-clear-period" name="period" class="vs-logs-input vs-logs-input-compact">
          <option value="7d">Older than 7 days</option>
          <option value="30d">Older than 30 days</option>
          <option value="all">All Logs (Reset)</option>
        </select>
        <button type="submit" class="vs-logs-btn vs-logs-btn-danger" aria-label="Clear selected security logs period">
          <img src="{{ asset('duotone/trash.svg') }}" alt="" class="es-duotone-icon es-icon-tone-coral" aria-hidden="true">
          Clear
        </button>
      </form>
    @endif
  </div>

  @if(!($edgeShieldMutationsAllowed ?? true))
    <div class="vs-logs-alert vs-logs-alert-warning">
      <img src="{{ asset('duotone/lock.svg') }}" alt="" class="es-duotone-icon es-icon-tone-brass" aria-hidden="true">
      <span>{{ $edgeShieldMutationBlockedMessage ?? 'Changes are disabled for this target.' }}</span>
    </div>
  @endif

  <form method="GET" action="{{ $logsIndexRoute }}" class="vs-logs-filter-form" aria-label="Security log filters">
    <div class="vs-logs-field">
      <label for="logs-domain-filter">Filter by domain</label>
      <select id="logs-domain-filter" class="vs-logs-input" name="domain_name">
        <option value="">All domains</option>
        @foreach(($domainOptions ?? []) as $optionDomain)
          <option value="{{ $optionDomain }}" @selected(($domainName ?? '') === $optionDomain)>{{ $optionDomain }}</option>
        @endforeach
      </select>
    </div>
    <div class="vs-logs-field">
      <label for="logs-event-filter">Filter by event type</label>
      <select id="logs-event-filter" class="vs-logs-input" name="event_type">
        <option value="">All events</option>
        @foreach(($eventTypeOptions ?? []) as $optionEvent)
          <option value="{{ $optionEvent }}" @selected(($eventType ?? '') === $optionEvent)>{{ $eventLabels[$optionEvent] ?? $optionEvent }}</option>
        @endforeach
      </select>
    </div>
    <div class="vs-logs-field">
      <label for="logs-ip-filter">Filter by IP</label>
      <input id="logs-ip-filter" class="vs-logs-input" name="ip_address" value="{{ $ipAddress ?? '' }}" placeholder="e.g. 203.0.113.10">
    </div>
    <div class="vs-logs-filter-actions">
      <button class="vs-logs-btn vs-logs-btn-primary" type="submit" aria-label="Apply security log filters">
        <img src="{{ asset('duotone/filter.svg') }}" alt="" class="es-duotone-icon" style="filter: brightness(0);" aria-hidden="true">
        Filter
      </button>
      <a class="vs-logs-btn vs-logs-btn-secondary" href="{{ $logsResetRoute }}" aria-label="Reset security log filters">Reset</a>
    </div>
  </form>

  @if($error)
    <div class="vs-logs-alert vs-logs-alert-danger">
      <img src="{{ asset('duotone/circle-exclamation.svg') }}" alt="" class="es-duotone-icon es-icon-tone-coral" aria-hidden="true">
      <span>{{ $error }}</span>
    </div>
  @endif
</section>
