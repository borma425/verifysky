@if(isset($generalStats))
  <div class="vs-logs-stats es-animate">
    <div class="vs-logs-stat vs-logs-stat-danger">
      <div class="vs-logs-stat-head">
        <span class="vs-logs-stat-icon" aria-hidden="true">
          <img src="{{ asset('duotone/skull-crossbones.svg') }}" alt="" class="es-duotone-icon es-icon-tone-coral">
        </span>
        <div class="min-w-0">
          <h3>Attacks Blocked This Month</h3>
          <span>{{ $scopeLabel ?? (($domainName ?? '') ?: 'ALL DOMAINS') }}</span>
        </div>
      </div>
      <div class="vs-logs-stat-value">{{ number_format($generalStats['total_attacks'] ?? 0) }}</div>
    </div>

    <div class="vs-logs-stat vs-logs-stat-success">
      <div class="vs-logs-stat-head">
        <span class="vs-logs-stat-icon" aria-hidden="true">
          <img src="{{ asset('duotone/shield-check.svg') }}" alt="" class="es-duotone-icon es-icon-tone-success">
        </span>
        <div class="min-w-0">
          <h3>Verified Users This Month</h3>
          <span>{{ $scopeLabel ?? (($domainName ?? '') ?: 'ALL DOMAINS') }}</span>
        </div>
      </div>
      <div class="vs-logs-stat-value">{{ number_format($generalStats['total_visitors'] ?? 0) }}</div>
    </div>

    <div class="vs-logs-stat vs-logs-stat-countries">
      <div class="vs-logs-stat-head">
        <span class="vs-logs-stat-icon" aria-hidden="true">
          <img src="{{ asset('duotone/radar.svg') }}" alt="" class="es-duotone-icon es-icon-tone-brass">
        </span>
        <div class="min-w-0">
          <h3>Top Attacking Countries (This Month)</h3>
          <span>{{ $scopeLabel ?? (($domainName ?? '') ?: 'ALL DOMAINS') }}</span>
        </div>
      </div>
      <div class="vs-logs-country-list">
        @php($hasCountryRows = false)
        @forelse($generalStats['top_countries'] ?? [] as $country)
          @if(($country['country'] ?? '') !== '')
            @php($hasCountryRows = true)
            <div class="vs-logs-country-row">
              <div class="vs-logs-country-name">
                <img src="https://flagcdn.com/w20/{{ strtolower($country['country']) }}.png" srcset="https://flagcdn.com/w40/{{ strtolower($country['country']) }}.png 2x" alt="{{ $country['country'] }}">
                <span>{{ strtoupper($country['country']) }}</span>
              </div>
              <span class="vs-logs-count-badge">{{ number_format($country['attack_count'] ?? 0) }}</span>
            </div>
          @endif
        @empty
          <div class="vs-logs-empty-inline">No data available</div>
        @endforelse
        @if(!empty($generalStats['top_countries']) && !$hasCountryRows)
          <div class="vs-logs-empty-inline">No data available</div>
        @endif
      </div>
    </div>
  </div>
@endif
