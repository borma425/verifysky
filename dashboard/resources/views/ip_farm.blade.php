@extends('layouts.app')

@section('content')
  <section class="vs-farm-page">
    <div class="vs-farm-hero">
      <div class="vs-farm-hero-copy">
        <span class="vs-farm-mark" aria-hidden="true">
          <img src="{{ asset('duotone/ban-bug.svg') }}" alt="" class="es-duotone-icon es-icon-tone-coral">
        </span>
        <div>
          <p class="vs-farm-eyebrow">Permanent Ban Network</p>
          <h2>IP Farm</h2>
          <p>Permanent IP and CIDR bans for your target environments. Global applies across every domain; domain targeting keeps the farm isolated.</p>
        </div>
      </div>
    </div>

    @if(!empty($loadErrors))
      <div class="vs-farm-alert">
        @foreach($loadErrors as $msg)
          <div>{{ $msg }}</div>
        @endforeach
      </div>
    @endif

    <div class="vs-farm-stats">
      <div class="vs-farm-stat vs-farm-stat-danger">
        <span class="vs-farm-stat-icon" aria-hidden="true"><img src="{{ asset('duotone/skull-crossbones.svg') }}" alt="" class="es-duotone-icon es-icon-tone-coral"></span>
        <div>
          <strong>{{ number_format($totalIps) }}</strong>
          <span>Banned IPs / CIDRs</span>
        </div>
      </div>
      <div class="vs-farm-stat">
        <span class="vs-farm-stat-icon" aria-hidden="true"><img src="{{ asset('duotone/spider-web.svg') }}" alt="" class="es-duotone-icon es-icon-tone-brass"></span>
        <div>
          <strong>{{ $totalRules }}</strong>
          <span>Farm Rules</span>
        </div>
      </div>
      <div class="vs-farm-stat vs-farm-stat-success">
        <span class="vs-farm-stat-icon" aria-hidden="true"><img src="{{ asset('duotone/clock.svg') }}" alt="" class="es-duotone-icon es-icon-tone-success"></span>
        <div>
          <strong>{{ $lastUpdated ? \Carbon\Carbon::parse($lastUpdated)->diffForHumans() : 'Never' }}</strong>
          <span>Last Updated</span>
        </div>
      </div>
    </div>

    <div class="vs-farm-layout">
      <aside class="vs-farm-create">
        <div class="vs-farm-card-head">
          <div>
            <p class="vs-farm-eyebrow">Seed Farm</p>
            <h3>Create IP Farm</h3>
          </div>
          <img src="{{ asset('duotone/circle-plus.svg') }}" alt="" class="es-duotone-icon es-icon-tone-brass h-4 w-4" aria-hidden="true">
        </div>

        @if(!$canAddFarmRule)
          <div class="vs-farm-alert vs-farm-alert-soft">
            {{ $firewallUsage['message'] ?? 'Custom firewall rule limit reached for this plan.' }}
          </div>
        @endif

        <form method="POST" action="{{ route('ip_farm.store') }}" class="vs-farm-form">
          @csrf
          <label>
            <span>Target Environment</span>
            <select class="vs-farm-input" name="domain_name" required @disabled(!$canAddFarmRule)>
              <option value="global">Global (All)</option>
              @foreach($domains as $domain)
                <option value="{{ $domain['domain_name'] }}">{{ $domain['domain_name'] }}</option>
              @endforeach
            </select>
          </label>
          <label>
            <span>Farm name</span>
            <input class="vs-farm-input" name="description" value="{{ old('description', 'Manual Farm') }}" @disabled(!$canAddFarmRule)>
          </label>
          <label>
            <span>IPs / CIDRs</span>
            <textarea class="vs-farm-input vs-farm-textarea" name="ips" placeholder="203.0.113.10&#10;198.51.100.0/24" required @disabled(!$canAddFarmRule)>{{ old('ips') }}</textarea>
          </label>
          <label class="vs-farm-check">
            <input type="checkbox" name="paused" value="1" @disabled(!$canAddFarmRule)>
            <span>Create as paused</span>
          </label>
          <button class="vs-farm-button vs-farm-button-primary" type="submit" @disabled(!$canAddFarmRule)>Create Farm</button>
        </form>
      </aside>

      <div class="vs-farm-network">
        @forelse($farmRules as $rule)
          @php
            $scope = ($rule['scope'] ?? '') === 'tenant' || ($rule['domain_name'] ?? '') === 'global' ? 'tenant' : 'domain';
            $cleanDescription = str_replace('[IP-FARM] ', '', preg_replace('/\s*\(\d+\s+IPs\)\s*$/', '', $rule['description']));
          @endphp

          <article class="vs-farm-node {{ $rule['paused'] ? 'vs-farm-node-paused' : '' }}">
            <div class="vs-farm-node-line" aria-hidden="true"></div>
            <div class="vs-farm-node-dot" aria-hidden="true"></div>

            <header class="vs-farm-node-head">
              <div class="vs-farm-node-title">
                <span class="vs-farm-node-icon" aria-hidden="true"><img src="{{ asset('duotone/bug-slash.svg') }}" alt="" class="es-duotone-icon es-icon-tone-coral"></span>
                <div>
                  <h3>{{ $cleanDescription }}</h3>
                  <p>#{{ $rule['id'] }} · {{ $scope === 'tenant' ? 'Global (All)' : $rule['domain_name'] }} · Permanent / No expiry</p>
                </div>
              </div>
              <div class="vs-farm-actions">
                <span class="vs-farm-pill {{ $rule['paused'] ? 'vs-farm-pill-warning' : 'vs-farm-pill-danger' }}">{{ $rule['paused'] ? 'Paused' : 'Active' }}</span>
                <span class="vs-farm-pill">{{ number_format($rule['ip_count']) }} targets</span>
                <form method="POST" action="{{ route('ip_farm.toggle', $rule['id']) }}">
                  @csrf
                  <input type="hidden" name="paused" value="{{ $rule['paused'] ? 0 : 1 }}">
                  <button class="vs-farm-icon-button" type="submit" title="{{ $rule['paused'] ? 'Enable Farm' : 'Pause Farm' }}" aria-label="{{ $rule['paused'] ? 'Enable Farm' : 'Pause Farm' }}">
                    <img src="{{ asset('duotone/'.($rule['paused'] ? 'play.svg' : 'pause.svg')) }}" alt="" class="es-duotone-icon es-icon-tone-muted" aria-hidden="true">
                  </button>
                </form>
                <form method="POST" action="{{ route('ip_farm.destroy', $rule['id']) }}" onsubmit="return confirm('Delete this IP Farm rule?')">
                  @csrf
                  @method('DELETE')
                  <button class="vs-farm-icon-button vs-farm-icon-danger" type="submit" title="Delete Farm" aria-label="Delete Farm">
                    <img src="{{ asset('duotone/trash.svg') }}" alt="" class="es-duotone-icon es-icon-tone-coral" aria-hidden="true">
                  </button>
                </form>
              </div>
            </header>

            <div class="vs-farm-node-body">
              <div class="vs-farm-meta">
                @if($rule['created_at'])
                  <span><img src="{{ asset('duotone/clock.svg') }}" alt="" class="es-duotone-icon es-icon-tone-muted" aria-hidden="true">Created {{ \Carbon\Carbon::parse($rule['created_at'])->format('Y-m-d H:i') }}</span>
                @endif
                @if($rule['updated_at'])
                  <span><img src="{{ asset('duotone/arrows-rotate.svg') }}" alt="" class="es-duotone-icon es-icon-tone-muted" aria-hidden="true">Updated {{ \Carbon\Carbon::parse($rule['updated_at'])->diffForHumans() }}</span>
                @endif
              </div>

              <div class="vs-farm-ip-grid">
                @forelse($rule['ips'] as $ip)
                  <span>{{ $ip }}</span>
                @empty
                  <em>No targets stored in this farm.</em>
                @endforelse
              </div>

              <details class="vs-farm-editor">
                <summary>
                  <img src="{{ asset('duotone/pen-to-square.svg') }}" alt="" class="es-duotone-icon es-icon-tone-brass" aria-hidden="true">
                  Edit Farm
                </summary>

                <div class="vs-farm-editor-grid">
                  <form method="POST" action="{{ route('ip_farm.update', $rule['id']) }}" class="vs-farm-form vs-farm-editor-main">
                    @csrf
                    @method('PUT')
                    <label>
                      <span>Target Environment</span>
                      <select class="vs-farm-input" name="domain_name" required>
                        <option value="global" @selected($scope === 'tenant')>Global (All)</option>
                        @foreach($domains as $domain)
                          <option value="{{ $domain['domain_name'] }}" @selected($scope === 'domain' && ($rule['domain_name'] ?? '') === $domain['domain_name'])>{{ $domain['domain_name'] }}</option>
                        @endforeach
                      </select>
                    </label>
                    <label>
                      <span>Farm name</span>
                      <input class="vs-farm-input" name="description" value="{{ $cleanDescription }}">
                    </label>
                    <label>
                      <span>Edit IPs / CIDRs</span>
                      <textarea class="vs-farm-input vs-farm-textarea vs-farm-textarea-tall" name="ips" required>{{ $rule['ips_text'] }}</textarea>
                    </label>
                    <label class="vs-farm-check">
                      <input type="checkbox" name="paused" value="1" @checked($rule['paused'])>
                      <span>Paused</span>
                    </label>
                    <button class="vs-farm-button vs-farm-button-primary" type="submit">Save Farm</button>
                  </form>

                  <div class="vs-farm-side-tools">
                    <form method="POST" action="{{ route('ip_farm.append', $rule['id']) }}" class="vs-farm-form">
                      @csrf
                      <label>
                        <span>Append IPs / CIDRs</span>
                        <textarea class="vs-farm-input vs-farm-textarea-small" name="ips" placeholder="203.0.113.25&#10;2001:db8::/64" required></textarea>
                      </label>
                      <button class="vs-farm-button vs-farm-button-secondary" type="submit">Append To Farm</button>
                    </form>

                    <form method="POST" action="{{ route('ip_farm.remove_ips', $rule['id']) }}" class="vs-farm-form">
                      @csrf
                      <label>
                        <span>Remove IPs / CIDRs From This Farm</span>
                        <textarea class="vs-farm-input vs-farm-textarea-small" name="ips" placeholder="203.0.113.10" required></textarea>
                      </label>
                      <button class="vs-farm-button vs-farm-button-danger" type="submit">Remove Targets</button>
                    </form>
                  </div>
                </div>
              </details>
            </div>
          </article>
        @empty
          <div class="vs-farm-empty">
            <img src="{{ asset('duotone/spider-web.svg') }}" alt="" class="es-duotone-icon es-icon-tone-muted" aria-hidden="true">
            <h3>No IP Farm rules yet</h3>
            <p>Create a manual farm or let the Worker auto-escalation add confirmed malicious IPs.</p>
          </div>
        @endforelse
      </div>
    </div>
  </section>
@endsection
