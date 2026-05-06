@extends('layouts.admin')

@section('content')
  <section class="vs-farm-page">
    <div class="vs-farm-admin-bar">
      <a href="{{ route('admin.tenants.show', $tenant) }}" class="vs-farm-back">Back to {{ $tenant->name }}</a>
      <div class="vs-farm-admin-links">
        <a href="{{ route('admin.tenants.firewall.index', $tenant) }}" class="es-btn es-btn-secondary">Firewall</a>
        <a href="{{ route('admin.tenants.sensitive_paths.index', $tenant) }}" class="es-btn es-btn-secondary">Protected Paths</a>
        <a href="{{ route('admin.tenants.customer.logs.index', $tenant) }}" class="es-btn es-btn-secondary">Logs</a>
      </div>
    </div>

    <div class="vs-farm-hero">
      <div class="vs-farm-hero-copy">
        <span class="vs-farm-mark" aria-hidden="true">
          <img src="{{ asset('duotone/ban-bug.svg') }}" alt="" class="es-duotone-icon es-icon-tone-coral">
        </span>
        <div>
          <p class="vs-farm-eyebrow">Blocked IPs</p>
          <h1>Blocked IP List</h1>
          <p>Permanent IP/CIDR blocks for all domains or one domain. Admin actions can exceed plan limits.</p>
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
          <strong>{{ number_format($stats['totalIps'] ?? 0) }}</strong>
          <span>Banned IPs / CIDRs</span>
        </div>
      </div>
      <div class="vs-farm-stat">
        <span class="vs-farm-stat-icon" aria-hidden="true"><img src="{{ asset('duotone/spider-web.svg') }}" alt="" class="es-duotone-icon es-icon-tone-brass"></span>
        <div>
          <strong>{{ $stats['totalRules'] ?? count($farmRules) }}</strong>
          <span>Block rules</span>
        </div>
      </div>
      <div class="vs-farm-stat vs-farm-stat-success">
        <span class="vs-farm-stat-icon" aria-hidden="true"><img src="{{ asset('duotone/clock.svg') }}" alt="" class="es-duotone-icon es-icon-tone-success"></span>
        <div>
          <strong>{{ $stats['lastUpdated'] ?? 'Never' }}</strong>
          <span>Last Updated</span>
        </div>
      </div>
    </div>

    <div class="vs-farm-layout">
      <aside class="vs-farm-create">
        <div class="vs-farm-card-head">
          <div>
            <p class="vs-farm-eyebrow">New block list</p>
            <h2>Create block list</h2>
          </div>
          <img src="{{ asset('duotone/circle-plus.svg') }}" alt="" class="es-duotone-icon es-icon-tone-brass h-4 w-4" aria-hidden="true">
        </div>

        <form method="POST" action="{{ route('admin.tenants.ip_farm.store', $tenant) }}" class="vs-farm-form">
          @csrf
          <label>
            <span>Apply to</span>
            <select class="vs-farm-input" name="domain_name" required>
              <option value="global">All domains</option>
              @foreach($domainRecords as $domain)
                <option value="{{ $domain->hostname }}">{{ $domain->hostname }}</option>
              @endforeach
            </select>
          </label>
          <label>
            <span>List name</span>
            <input class="vs-farm-input" name="description" value="{{ old('description', 'Manual list') }}" placeholder="List name">
          </label>
          <label>
            <span>IPs / CIDRs</span>
            <textarea class="vs-farm-input vs-farm-textarea" name="ips" placeholder="203.0.113.10&#10;198.51.100.0/24" required>{{ old('ips') }}</textarea>
          </label>
          <label class="vs-farm-check">
            <input type="checkbox" name="paused" value="1">
            <span>Create as paused</span>
          </label>
          <button class="vs-farm-button vs-farm-button-primary" type="submit">Create list</button>
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
                  <p>#{{ $rule['id'] }} - {{ $scope === 'tenant' ? 'All domains' : $rule['domain_name'] }} - Permanent / No expiry</p>
                </div>
              </div>
              <div class="vs-farm-actions">
                <span class="vs-farm-pill {{ $rule['paused'] ? 'vs-farm-pill-warning' : 'vs-farm-pill-danger' }}">{{ $rule['paused'] ? 'Paused' : 'Active' }}</span>
                <span class="vs-farm-pill">{{ number_format($rule['ip_count']) }} targets</span>
                <form method="POST" action="{{ route('admin.tenants.ip_farm.toggle', [$tenant, $rule['id']]) }}">
                  @csrf
                  <input type="hidden" name="paused" value="{{ $rule['paused'] ? 0 : 1 }}">
                  <button class="vs-farm-icon-button" type="submit" title="{{ $rule['paused'] ? 'Enable Farm' : 'Pause Farm' }}" aria-label="{{ $rule['paused'] ? 'Enable Farm' : 'Pause Farm' }}">
                    <img src="{{ asset('duotone/'.($rule['paused'] ? 'play.svg' : 'pause.svg')) }}" alt="" class="es-duotone-icon es-icon-tone-muted" aria-hidden="true">
                  </button>
                </form>
                <form method="POST" action="{{ route('admin.tenants.ip_farm.destroy', [$tenant, $rule['id']]) }}" onsubmit="return confirm('Delete this blocked IP rule?')">
                  @csrf
                  @method('DELETE')
                  <button class="vs-farm-icon-button vs-farm-icon-danger" type="submit" title="Delete Farm" aria-label="Delete Farm">
                    <img src="{{ asset('duotone/trash.svg') }}" alt="" class="es-duotone-icon es-icon-tone-coral" aria-hidden="true">
                  </button>
                </form>
              </div>
            </header>

            <div class="vs-farm-node-body">
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
                  Edit list
                </summary>

                <div class="vs-farm-editor-grid">
                  <form method="POST" action="{{ route('admin.tenants.ip_farm.update', [$tenant, $rule['id']]) }}" class="vs-farm-form vs-farm-editor-main">
                    @csrf
                    @method('PUT')
                    <label>
                      <span>Apply to</span>
                      <select class="vs-farm-input" name="domain_name" required>
                        <option value="global" @selected($scope === 'tenant')>All domains</option>
                        @foreach($domainRecords as $domain)
                          <option value="{{ $domain->hostname }}" @selected($scope === 'domain' && ($rule['domain_name'] ?? '') === $domain->hostname)>{{ $domain->hostname }}</option>
                        @endforeach
                      </select>
                    </label>
                    <label>
                      <span>List name</span>
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
                    <button class="vs-farm-button vs-farm-button-primary" type="submit">Save list</button>
                  </form>

                  <div class="vs-farm-side-tools">
                    <form method="POST" action="{{ route('admin.tenants.ip_farm.append', [$tenant, $rule['id']]) }}" class="vs-farm-form">
                      @csrf
                      <label>
                        <span>Append IPs / CIDRs</span>
                        <textarea class="vs-farm-input vs-farm-textarea-small" name="ips" placeholder="203.0.113.25&#10;2001:db8::/64" required></textarea>
                      </label>
                      <button class="vs-farm-button vs-farm-button-secondary" type="submit">Add to list</button>
                    </form>

                    <form method="POST" action="{{ route('admin.tenants.ip_farm.remove_ips', [$tenant, $rule['id']]) }}" class="vs-farm-form">
                      @csrf
                      <label>
                        <span>Remove IPs / CIDRs from this list</span>
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
            <h3>This blocked IP list is empty.</h3>
          </div>
        @endforelse
      </div>
    </div>
  </section>
@endsection
