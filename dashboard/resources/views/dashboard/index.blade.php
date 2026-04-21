@extends('layouts.app')

@section('content')
  @php
    $sessionTrustCount = number_format($stats['total_visitors_today'] ?? 0);

    $leftSignals = [
      ['label' => 'Traffic Ingress', 'value' => number_format($stats['total_visitors_today'] ?? 0)],
      ['label' => 'Bot Signals', 'value' => number_format($stats['total_attacks_today'] ?? 0)],
      ['label' => 'Policy Checks', 'value' => !empty($stats['recent_critical']) ? 'Live' : 'Idle'],
      ['label' => 'Session Trust', 'value' => $sessionTrustCount],
    ];

    $services = [
      [
        'label' => 'Protected Domains',
        'meta' => number_format($stats['active_domains'] ?? 0) . ' active',
        'icon' => 'globe.svg',
      ],
      [
        'label' => 'Threat Blocks',
        'meta' => number_format($stats['total_attacks_today'] ?? 0) . ' today',
        'icon' => 'shield-keyhole.svg',
      ],
      [
        'label' => 'Target Focus',
        'meta' => preg_replace('/^www\./i', '', $stats['top_domains'][0]['domain_name'] ?? 'No target'),
        'icon' => 'network-wired.svg',
      ],
      [
        'label' => 'Session Trust',
        'meta' => $sessionTrustCount . ' verified',
        'icon' => 'circle-check.svg',
      ],
    ];

    $kpis = [
      [
        'label' => 'Traffic',
        'value' => number_format($stats['total_visitors_today'] ?? 0),
        'meta' => !empty($stats['top_countries']) ? strtoupper($stats['top_countries'][0]['country'] ?? '') . ' active' : 'Live telemetry',
        'icon' => 'signal-good.svg',
        'points' => '0,28 16,20 30,24 44,12 58,18 72,8 86,14 100,4',
      ],
      [
        'label' => 'Domains',
        'value' => number_format($stats['active_domains'] ?? 0),
        'meta' => number_format(count($stats['top_domains'] ?? [])) . ' high focus',
        'icon' => 'globe.svg',
        'points' => '0,24 16,26 32,22 48,19 64,17 80,19 92,22 100,28',
      ],
      [
        'label' => 'Blocks',
        'value' => number_format($stats['total_attacks_today'] ?? 0),
        'meta' => !empty($stats['recent_critical']) ? 'Critical events detected' : 'No critical activity',
        'icon' => 'shield-keyhole.svg',
        'points' => '0,30 16,24 32,18 48,22 64,10 80,18 100,14',
        'danger' => !empty($stats['recent_critical']),
      ],
      [
        'label' => 'Uptime',
        'value' => '99.99%',
        'meta' => 'Enterprise SLA compliant',
        'icon' => 'circle-check.svg',
        'points' => '0,22 20,22 40,22 60,22 80,22 100,22',
      ],
    ];
  @endphp

  <section class="es-overview-page es-animate">
    <div class="es-overview-shell">
      @if($billingStatus)
        @if($billingStatus['active_grant'])
          <div class="mb-4 rounded-xl border border-[#FCB900]/20 bg-[#FCB900]/10 px-4 py-3 text-sm text-[#FFE6B5]">
            Manual {{ strtoupper($billingStatus['active_grant']['granted_plan_key']) }} grant active until {{ $billingStatus['active_grant']['ends_at']?->format('Y-m-d') }}.
          </div>
        @endif

        <div class="es-usage-grid">
          @foreach([
            [
              'title' => 'Protected Sessions',
              'metric' => $billingStatus['protected_sessions'],
              'meta' => 'Current cycle protection volume',
            ],
            [
              'title' => 'Bot Requests Rejected',
              'metric' => $billingStatus['bot_requests'],
              'meta' => 'Fair-use blocked or challenged traffic',
            ],
          ] as $usageCard)
            <div class="es-usage-card">
              <div class="es-usage-card-head">
                <div>
                  <p class="es-usage-card-kicker">{{ $billingStatus['plan_name'] }} Plan</p>
                  <h2 class="es-usage-card-title">{{ $usageCard['title'] }}</h2>
                </div>
                <span class="es-usage-card-badge es-usage-card-badge-{{ $usageCard['metric']['level'] }}">
                  {{ $usageCard['metric']['percentage'] }}%
                </span>
              </div>
              <div class="es-usage-card-value">
                {{ $usageCard['metric']['formatted_used'] }}
                <span>/ {{ $usageCard['metric']['formatted_limit'] }}</span>
              </div>
              <p class="es-usage-card-meta">{{ $usageCard['meta'] }}</p>
              <div class="es-usage-progress">
                <div class="es-usage-progress-bar es-usage-progress-bar-{{ $usageCard['metric']['level'] }}" style="width: {{ $usageCard['metric']['percentage'] }}%"></div>
              </div>
              <div class="es-usage-card-foot">
                <span>{{ $usageCard['metric']['formatted_remaining'] }} remaining this cycle</span>
                <span>{{ $billingStatus['current_cycle_end_at']->format('Y-m-d') }} reset</span>
              </div>
            </div>
          @endforeach
        </div>
      @endif

      <div class="es-overview-header">
        <div>
          <p class="es-overview-kicker">VerifySky Operations</p>
          <h1 class="es-overview-title">Control Plane</h1>
          <p class="es-overview-subtitle">Unified visibility. Intelligent routing. Assured delivery.</p>
        </div>

        <div class="es-overview-live-pill">
          <span class="es-overview-live-dot"></span>
          <span>System Live</span>
        </div>
      </div>

      <div class="es-topology-frame">
        <div class="es-topology-grid"></div>
        <svg class="es-topology-lines" viewBox="0 0 1000 520" role="img" aria-label="VerifySky control plane topology">
          <path class="es-topology-line-primary" d="M120 120 C300 120 340 180 455 215" />
          <path class="es-topology-line-accent" d="M120 205 C300 205 340 225 455 245" />
          <path class="es-topology-line-secondary" d="M120 290 C300 290 340 265 455 275" />
          <path class="es-topology-line-primary" d="M120 375 C300 375 340 310 455 305" />

          <path class="es-topology-line-accent" d="M545 215 C660 180 700 120 880 120" />
          <path class="es-topology-line-primary" d="M545 245 C660 225 700 205 880 205" />
          <path class="es-topology-line-secondary" d="M545 275 C660 265 700 290 880 290" />
          <path class="es-topology-line-accent" d="M545 305 C660 310 700 375 880 375" />

          @foreach([[120,120],[120,205],[120,290],[120,375],[880,120],[880,205],[880,290],[880,375]] as [$cx, $cy])
            <circle class="es-topology-node es-topology-node-live" cx="{{ $cx }}" cy="{{ $cy }}" r="5" />
          @endforeach
        </svg>

        <div class="es-topology-layout">
          <div class="es-topology-column es-topology-column-left">
            @foreach($leftSignals as $signal)
              <div class="es-topology-metric-pill">
                <span class="es-topology-pulse"></span>
                <span class="es-topology-metric-copy">
                  <span class="es-topology-metric-label">{{ $signal['label'] }}</span>
                  <span class="es-topology-metric-value">{{ $signal['value'] }}</span>
                </span>
              </div>
            @endforeach
          </div>

          <div class="es-topology-center">
            <div class="es-topology-ring es-topology-ring-lg"></div>
            <div class="es-topology-ring es-topology-ring-sm"></div>
            <div class="es-topology-core-shell">
              <div class="es-topology-core">
                <img src="{{ asset('Logo.png') }}" alt="VerifySky" class="h-14 w-14 object-contain">
              </div>
            </div>
          </div>

          <div class="es-topology-column es-topology-column-right">
            @foreach($services as $service)
              <div class="es-topology-service-pill">
                <span class="es-topology-pulse"></span>
                <img src="{{ asset('duotone/'.$service['icon']) }}" alt="{{ $service['label'] }}" class="es-duotone-icon es-overview-icon h-5 w-5">
                <span class="min-w-0">
                  <span class="block truncate">{{ $service['label'] }}</span>
                  <span class="mt-1 block truncate text-[10px] uppercase tracking-[0.14em] text-[#AEB9CC]">{{ $service['meta'] }}</span>
                </span>
              </div>
            @endforeach
          </div>
        </div>
      </div>

      <div class="es-overview-kpi-grid">
        @foreach($kpis as $metric)
          <div class="es-overview-kpi-card">
            <div class="es-overview-kpi-head">
              <span>{{ $metric['label'] }}</span>
              <img src="{{ asset('duotone/'.$metric['icon']) }}" alt="{{ $metric['label'] }}" class="es-duotone-icon es-overview-icon h-4 w-4">
            </div>
            <div class="es-overview-kpi-value">{{ $metric['value'] }}</div>
            <div class="es-overview-kpi-meta {{ !empty($metric['danger']) ? 'es-overview-kpi-meta-danger' : '' }}">{{ $metric['meta'] }}</div>
            <svg class="es-overview-sparkline" viewBox="0 0 100 36" preserveAspectRatio="none" aria-hidden="true">
              <polyline points="{{ $metric['points'] }}" fill="none" stroke="currentColor" stroke-width="2"/>
            </svg>
          </div>
        @endforeach
      </div>
    </div>
  </section>

  <div class="mt-6 grid grid-cols-1 gap-4 md:grid-cols-2 es-animate es-animate-delay">
    <div class="es-card p-5">
      <h3 class="mb-4 flex items-center gap-2 text-sm font-bold text-[#FFFFFF]">
        <img src="{{ asset('duotone/globe.svg') }}" alt="countries" class="es-duotone-icon es-overview-icon h-4 w-4">
        Top Attacking Countries
      </h3>
      <div class="flex flex-col gap-3">
        @forelse($stats['top_countries'] ?? [] as $tc)
          <div class="flex items-center justify-between text-sm">
            <div class="flex items-center gap-2">
              <img src="https://flagcdn.com/w20/{{ strtolower($tc['country'] ?? '') }}.png" srcset="https://flagcdn.com/w40/{{ strtolower($tc['country'] ?? '') }}.png 2x" alt="{{ $tc['country'] ?? '' }}" class="h-auto w-5 rounded-sm border border-white/10 object-cover">
              <span class="font-medium text-[#D7E1F5]">{{ strtoupper($tc['country'] ?? '') }}</span>
            </div>
            <span class="rounded-md border border-[#D47B78]/30 bg-[#D47B78]/12 px-2 py-1 text-xs font-bold text-[#FFE6E3]">{{ number_format($tc['attack_count'] ?? 0) }}</span>
          </div>
        @empty
          <div class="text-xs text-[#959BA7]">No attacks recorded today.</div>
        @endforelse
      </div>
    </div>

    <div class="es-card p-5">
      <h3 class="mb-4 flex items-center gap-2 text-sm font-bold text-[#FFFFFF]">
        <img src="{{ asset('duotone/network-wired.svg') }}" alt="domains" class="es-duotone-icon es-overview-icon h-4 w-4">
        Top Targeted Domains
      </h3>
      <div class="flex flex-col gap-3">
        @forelse($stats['top_domains'] ?? [] as $td)
          <div class="flex items-center justify-between text-sm">
            <span class="font-medium text-[#D7E1F5]">{{ preg_replace('/^www\./i', '', $td['domain_name'] ?? '-') }}</span>
            <span class="rounded-md border border-[#D47B78]/30 bg-[#D47B78]/12 px-2 py-1 text-xs font-bold text-[#FFE6E3]">{{ number_format($td['attack_count'] ?? 0) }}</span>
          </div>
        @empty
          <div class="text-xs text-[#959BA7]">No attacks recorded today.</div>
        @endforelse
      </div>
    </div>
  </div>

  <div class="es-card es-animate es-animate-delay-2 mt-6 p-5 md:p-6">
    <div class="mb-4 flex items-center justify-between gap-3">
      <h3 class="flex items-center gap-2 text-lg font-bold text-[#FFFFFF]">
        <img src="{{ asset('duotone/triangle-exclamation.svg') }}" alt="critical" class="es-duotone-icon es-icon-tone-coral h-4 w-4">
        Recent Critical Blocks
      </h3>
      <a href="{{ route('logs.index') }}" class="es-btn es-btn-secondary es-btn-compact">View All Logs</a>
    </div>
    <div class="overflow-x-auto">
      <table class="es-table min-w-full">
        <thead>
          <tr>
            <th>IP Address</th>
            <th>Country</th>
            <th>Target Domain</th>
            <th>Details</th>
            <th>Time</th>
          </tr>
        </thead>
        <tbody>
        @forelse($stats['recent_critical'] ?? [] as $row)
          <tr>
            <td><span class="font-mono text-[#FFE6E3]">{{ $row['ip_address'] ?? '' }}</span></td>
            <td>
              @if(!empty($row['country']) && $row['country'] !== 'T1')
                <div class="flex items-center gap-1.5">
                  <img src="https://flagcdn.com/w20/{{ strtolower($row['country']) }}.png" srcset="https://flagcdn.com/w40/{{ strtolower($row['country']) }}.png 2x" alt="{{ $row['country'] }}" class="h-auto w-4 rounded-[2px]">
                  <span>{{ strtoupper($row['country']) }}</span>
                </div>
              @else
                -
              @endif
            </td>
            <td>{{ preg_replace('/^www\./i', '', $row['domain_name'] ?? '-') }}</td>
            <td class="max-w-xs truncate" title="{{ $row['details'] ?? '' }}">{{ $row['details'] ?: 'Malicious Activity / Hard Block' }}</td>
            <td>{{ $row['created_at'] ? \Carbon\Carbon::parse($row['created_at'])->diffForHumans() : 'Unknown' }}</td>
          </tr>
        @empty
          <tr><td colspan="5" class="py-6 text-center text-sm text-[#959BA7]">No critical events recorded.</td></tr>
        @endforelse
        </tbody>
      </table>
    </div>
  </div>
@endsection
