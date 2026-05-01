<section class="vs-logs-panel vs-logs-table-panel es-animate es-animate-delay" aria-labelledby="logs-table-title">
  <div class="vs-logs-table-head">
    <div>
      <h3 id="logs-table-title">{{ $isTenantScoped ? 'Recent Security Events' : 'Recent Security Log Events' }}</h3>
      <p>
        {{ $isTenantScoped ? 'Only events for the domains assigned to your account are shown here.' : 'Grouped by IP and domain to help with investigation and enforcement.' }}
      </p>
    </div>
  </div>

  <div class="vs-logs-table-scroll">
    <table class="vs-logs-table">
      <thead>
      <tr>
        <th>Domain</th>
        <th>Event</th>
        <th>IP</th>
        <th>Attacks</th>
        @if($canManageLogActions)
          <th>Action</th>
        @endif
        <th>ASN</th>
        <th>Country</th>
        <th>Path</th>
        <th>Details</th>
        <th>Time</th>
      </tr>
      </thead>
      <tbody>
      @forelse($logs as $row)
        @php
          $severityTone = $row['severity_tone'] ?? 'info';
          $rowTone = 'vs-logs-row-'.$severityTone;
        @endphp
        <tr class="{{ $rowTone }}">
          <td data-label="Domain">
            <span class="vs-logs-domain">{{ $row['domain'] ?? '-' }}</span>
          </td>
          <td data-label="Event">
            <div class="vs-logs-event-stack">
              <div class="vs-logs-event-line">
                <span class="vs-logs-event-name">{{ $row['event_display'] ?? '' }}</span>
                <span class="vs-logs-severity-badge vs-logs-severity-{{ $severityTone }}">
                  {{ $row['severity_label'] ?? 'Info' }}
                </span>
                <span class="vs-logs-risk-badge" aria-label="Risk score {{ $row['event_score'] ?? 0 }} percent">{{ $row['event_score'] ?? 0 }}%</span>
              </div>
              @if(!empty($row['is_repeat_offender']))
                <span class="vs-logs-repeat-badge">REPEAT OFFENDER</span>
              @endif
            </div>
          </td>
          <td data-label="IP">
            <span class="vs-logs-mono">{{ $row['ip_address'] ?? '' }}</span>
          </td>
          <td data-label="Attacks">
            <div class="vs-logs-attack-stack">
              <span>T: {{ $row['requests_today'] ?? 0 }}</span>
              <span>Y: {{ $row['requests_yesterday'] ?? 0 }}</span>
              <span>M: {{ $row['requests_month'] ?? 0 }}</span>
            </div>
          </td>
          @if($canManageLogActions)
            <td data-label="Action">
              @if(!empty($row['can_allow']))
                @if(!empty($row['prefer_block_action']))
                  <form method="POST" action="{{ route('logs.block_ip') }}" class="vs-logs-action-form">
                    @csrf
                    <input type="hidden" name="ip" value="{{ $row['ip_address'] }}">
                    <input type="hidden" name="domain" value="{{ $row['domain'] }}">
                    <button type="submit" class="vs-logs-action-btn vs-logs-action-danger" title="Block IP for 24h" aria-label="Block IP {{ $row['ip_address'] }}">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M16 21v-2a4 4 0 0 0-4-4H5c-2 0-4 1.8-4 4v2"></path>
                        <circle cx="8.5" cy="7" r="4"></circle>
                        <line x1="18" y1="8" x2="23" y2="13"></line>
                        <line x1="23" y1="8" x2="18" y2="13"></line>
                      </svg>
                    </button>
                  </form>
                @else
                  <form method="POST" action="{{ route('logs.allow_ip') }}" class="vs-logs-action-form">
                    @csrf
                    <input type="hidden" name="ip" value="{{ $row['ip_address'] }}">
                    <input type="hidden" name="domain" value="{{ $row['domain'] }}">
                    <button type="submit" class="vs-logs-action-btn vs-logs-action-success" title="Allow-list IP and reset ban" aria-label="Allow-list IP {{ $row['ip_address'] }} and reset ban">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M21 12a9 9 0 1 1-2.64-6.36"></path>
                        <path d="M21 3v6h-6"></path>
                      </svg>
                    </button>
                  </form>
                @endif
              @else
                <span class="vs-logs-na">N/A</span>
              @endif
            </td>
          @endif
          <td data-label="ASN">
            <span class="vs-logs-mono">{{ $row['asn'] ?? '' }}</span>
          </td>
          <td data-label="Country">
            <span class="vs-logs-country-code">{{ $row['country'] ?? '' }}</span>
          </td>
          <td data-label="Path" class="vs-logs-path-cell">
            <div class="vs-logs-path-list">
              @forelse(($row['top_paths'] ?? []) as $path)
                <div class="vs-logs-path" title="{{ $path }}">{{ $path }}</div>
              @empty
                <span class="vs-logs-na">-</span>
              @endforelse
            </div>
            @if(count($row['recent_paths'] ?? []) > 2)
              <details class="vs-logs-path-tooltip">
                <summary class="vs-logs-path-trigger" title="Show last 50 paths" aria-label="Show last {{ count($row['recent_paths']) }} paths">+</summary>
                <div class="vs-logs-path-panel">
                  <div class="vs-logs-path-panel-title">Last {{ count($row['recent_paths']) }} paths</div>
                  <div class="vs-logs-path-panel-list">
                    @foreach($row['recent_paths'] as $path)
                      <div>{{ $path }}</div>
                    @endforeach
                  </div>
                </div>
              </details>
            @endif
          </td>
          <td data-label="Details" class="vs-logs-details-cell">
            @if(!empty($row['details_items']))
              <div class="vs-logs-details-list">
                @foreach($row['details_items'] as $detail)
                  <div>
                    <span>{{ $detail['label'] }}:</span>
                    <strong>{{ $detail['value'] }}</strong>
                  </div>
                @endforeach
              </div>
            @else
              <span class="vs-logs-detail-fallback">{{ $row['details_fallback'] ?? '' }}</span>
            @endif
          </td>
          <td data-label="Time">
            <span class="vs-logs-time" title="{{ $row['created_at'] ?? '' }}">{{ $row['created_at_human'] ?? ($row['created_at'] ?? '') }}</span>
            <span class="vs-logs-time-raw">{{ $row['created_at'] ?? '' }}</span>
          </td>
        </tr>
      @empty
        <tr>
          <td colspan="{{ $canManageLogActions ? 10 : 9 }}" class="vs-logs-empty-row">
            {{ $emptyStateMessage ?? 'No logs.' }}
          </td>
        </tr>
      @endforelse
      </tbody>
    </table>
  </div>

  @if($logs->hasPages())
    <div class="vs-logs-pagination">{{ $logs->onEachSide(1)->links() }}</div>
  @endif
</section>
