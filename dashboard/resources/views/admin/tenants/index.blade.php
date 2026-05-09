@extends('layouts.admin')

@section('content')
  @php
    $billingTerms = app(\App\ViewData\BillingTerminologyViewData::class);
    $tenantCollection = collect($tenantRows);
    $totalUsers = isset($tenants) ? $tenants->total() : $tenantCollection->count();
    $totalDomains = $tenantCollection->sum(fn ($row) => (int) ($row['domains_count'] ?? 0));
    $activeBonuses = $tenantCollection->filter(fn ($row) => ! empty($row['active_grant']))->count();
    $cloudflareEstimated = $tenantCollection->sum(fn ($row) => (float) data_get($row, 'cloudflare_cost.summary.estimated_cost_usd', 0));
    $tenantRangeStart = isset($tenants) && $tenants->total() > 0 ? $tenants->firstItem() : 0;
    $tenantRangeEnd = isset($tenants) && $tenants->total() > 0 ? $tenants->lastItem() : 0;

    $metricCards = [
      ['label' => 'Users', 'value' => number_format($totalUsers), 'icon' => 'user-secret.svg'],
      ['label' => 'Domains', 'value' => number_format($totalDomains), 'icon' => 'spider-web.svg'],
      ['label' => 'Bonuses', 'value' => number_format($activeBonuses), 'icon' => 'sack-dollar.svg'],
      ['label' => 'CF Cost', 'value' => '$'.number_format($cloudflareEstimated, 2), 'icon' => 'cloud-check.svg'],
    ];

    $headerIcons = [
      'User' => 'user-secret.svg',
      'Plan Limit' => 'sliders.svg',
      'Total Limit' => 'gauge-circle-bolt.svg',
      'Billing Cycle' => 'arrows-rotate.svg',
      'Bonus' => 'sack-dollar.svg',
      'Domains' => 'spider-web.svg',
      'Cloudflare Cost' => 'cloud-check.svg',
      'Actions' => 'gear.svg',
    ];
  @endphp

  <section class="es-animate">
    <div class="mb-5 flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
      <div class="min-w-0">
        <p class="mb-1 text-xs font-semibold uppercase tracking-[0.1em] text-[#D4C4AB]">Clients</p>
        <h1 class="text-3xl font-extrabold tracking-wide text-[#DEE2F0]">Users</h1>
        <p class="mt-2 text-sm text-[#AEB9CC]">Manage users, domains, billing, bonuses, and usage.</p>
      </div>

      <a href="{{ route('admin.overview') }}" class="es-btn es-btn-secondary inline-flex w-fit items-center gap-2">
        <img src="{{ asset('duotone/radar.svg') }}" alt="" class="es-duotone-icon es-icon-tone-muted h-4 w-4">
        Overview
      </a>
    </div>

    <div class="mb-4 grid gap-2 md:grid-cols-4">
      @foreach($metricCards as $card)
        <div class="flex min-h-16 items-center justify-between gap-3 rounded-lg border border-[#303540] bg-[#1B202A] px-4 py-3">
          <div class="min-w-0">
            <div class="truncate text-[11px] font-bold uppercase tracking-[0.14em] text-[#7F8BA0]">{{ $card['label'] }}</div>
            <div class="mt-1 text-xl font-black leading-none text-[#FFFFFF]">{{ $card['value'] }}</div>
          </div>
          <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-md border border-[#303540] bg-[#252A34]">
            <img src="{{ asset('duotone/'.$card['icon']) }}" alt="" class="es-duotone-icon es-icon-tone-brass h-4 w-4">
          </span>
        </div>
      @endforeach
    </div>

    @unless($billingAvailable)
      <div class="mb-4 flex items-start gap-3 rounded-lg border border-[#D47B78]/35 bg-[#D47B78]/14 px-4 py-3 text-sm text-[#FFE6E3]">
        <img src="{{ asset('duotone/triangle-exclamation.svg') }}" alt="" class="es-duotone-icon es-icon-tone-coral mt-0.5 h-5 w-5 shrink-0">
        <div>
          <strong class="text-[#FFFFFF]">Billing setup is pending.</strong>
          Run billing migrations before changing bonuses, subscriptions, or usage.
        </div>
      </div>
    @endunless

    <div class="overflow-hidden rounded-lg border border-[#303540] bg-[#1B202A]">
      <div class="flex flex-col gap-3 border-b border-[#303540]/80 px-4 py-3 lg:flex-row lg:items-center lg:justify-between">
        <div class="flex items-center gap-3">
          <span class="inline-flex h-8 w-8 items-center justify-center rounded-md border border-[#303540] bg-[#252A34]">
            <img src="{{ asset('duotone/chart-network.svg') }}" alt="" class="es-duotone-icon es-icon-tone-brass h-4 w-4">
          </span>
          <div>
            <h2 class="text-sm font-extrabold text-[#FFFFFF]">Tenant control plane</h2>
            <p class="text-xs text-[#AEB9CC]">Showing {{ number_format($tenantRangeStart) }} to {{ number_format($tenantRangeEnd) }} of {{ number_format($totalUsers) }} users</p>
          </div>
        </div>
        <div class="flex flex-wrap gap-2">
          <span class="inline-flex items-center gap-2 rounded-full border border-[#303540] bg-[#252A34] px-3 py-1 text-xs font-bold text-[#D7E1F5]">
            <span class="h-1.5 w-1.5 rounded-full {{ $billingAvailable ? 'bg-[#FCB900]' : 'bg-[#D47B78]' }}"></span>
            {{ $billingAvailable ? 'Billing Ready' : 'Billing Pending' }}
          </span>
          <span class="inline-flex rounded-full border border-[#303540] bg-[#252A34] px-3 py-1 text-xs font-bold text-[#AEB9CC]">25 per page</span>
        </div>
      </div>

      <div class="overflow-x-auto">
        <table class="w-full min-w-[1260px] border-separate border-spacing-0 text-sm text-[#D7E1F5]">
          <thead class="bg-[#090E18]">
          <tr>
            @foreach($headerIcons as $label => $icon)
              <th class="border-b border-[#303540] px-4 py-3 text-left text-[11px] font-black uppercase tracking-[0.12em] text-[#7F8BA0]">
                <span class="inline-flex items-center gap-2">
                  <img src="{{ asset('duotone/'.$icon) }}" alt="" class="es-duotone-icon es-icon-tone-muted h-3.5 w-3.5 opacity-70">
                  {{ $label }}
                </span>
              </th>
            @endforeach
          </tr>
          </thead>
          <tbody class="divide-y divide-[#303540]/70">
          @forelse($tenantRows as $row)
            @php
              $tenant = $row['tenant'];
              $billing = $row['billing'];
              $grant = $row['active_grant'];
              $cf = $row['cloudflare_cost'] ?? [];
              $source = $billingTerms->sourceLabel($row['effective_plan']['source'] ?? 'baseline');
              $sessions = $billing['protected_sessions'] ?? null;
              $bots = $billing['bot_requests'] ?? null;
              $sessionPercent = $sessions ? min(100, max(0, (int) $sessions['percentage'])) : 0;
              $botPercent = $bots ? min(100, max(0, (int) $bots['percentage'])) : 0;
              $profit = (float) ($cf['profit_usd'] ?? 0);
            @endphp
            <tr class="bg-[#171C26] transition-colors hover:bg-[#1B202A]">
              <td class="px-4 py-4 align-top">
                <div class="flex min-w-[10rem] items-start gap-3">
                  <span class="mt-0.5 inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-md border border-[#303540] bg-[#252A34]">
                    <img src="{{ asset('duotone/user-secret.svg') }}" alt="" class="es-duotone-icon es-icon-tone-brass h-4 w-4">
                  </span>
                  <div class="min-w-0">
                    <div class="truncate font-bold text-[#FFFFFF]">{{ $tenant->name }}</div>
                    <div class="mt-1 font-mono text-[11px] text-[#AEB9CC]">#{{ $tenant->id }}</div>
                    <div class="mt-0.5 truncate font-mono text-[11px] text-[#7F8BA0]">{{ $tenant->slug }}</div>
                  </div>
                </div>
              </td>
              <td class="px-4 py-4 align-top">
                <span class="inline-flex rounded-md border border-[#303540] bg-[#252A34] px-2 py-1 text-xs font-bold text-[#D7E1F5]">
                  {{ $row['baseline_plan']['name'] ?? ucfirst((string) $tenant->plan) }}
                </span>
              </td>
              <td class="px-4 py-4 align-top">
                <div class="font-bold text-[#FFFFFF]">{{ $row['effective_plan']['name'] ?? 'Free' }}</div>
                <div class="mt-1 inline-flex rounded-full border border-[#FCB900]/20 bg-[#FCB900]/10 px-2 py-0.5 text-[11px] font-bold text-[#FFDC9C]">{{ $source }}</div>
              </td>
              <td class="px-4 py-4 align-top">
                @if($billing && $sessions && $bots)
                  <div class="grid min-w-[14rem] max-w-[18rem] gap-2">
                    <div class="flex items-center gap-2">
                      <span class="rounded-full border {{ $billing['is_pass_through'] ? 'border-[#D47B78]/30 bg-[#D47B78]/12 text-[#FFE6E3]' : 'border-[#FCB900]/24 bg-[#FCB900]/10 text-[#FFDC9C]' }} px-2 py-0.5 text-[11px] font-bold">
                        {{ str_replace('_', ' ', $billing['quota_status']) }}
                      </span>
                    </div>
                    <div>
                      <div class="flex items-center justify-between gap-3 text-[11px]">
                        <span class="font-bold text-[#D7E1F5]">Sessions</span>
                        <span class="font-mono text-[#AEB9CC]">{{ $sessions['formatted_used'] }} / {{ $sessions['formatted_limit'] }}</span>
                      </div>
                      <div class="mt-1.5 h-1 overflow-hidden rounded-full bg-[#303540]">
                        <div class="h-full rounded-full bg-[#FCB900]" style="width: {{ $sessionPercent }}%"></div>
                      </div>
                      @include('partials.billing-limit-equation', ['equation' => $billingTerms->billingMetricEquation($billing, $sessions, 'protected_sessions'), 'class' => 'mt-1'])
                    </div>
                    <div>
                      <div class="flex items-center justify-between gap-3 text-[11px]">
                        <span class="font-bold text-[#D7E1F5]">Bots</span>
                        <span class="font-mono text-[#AEB9CC]">{{ $bots['formatted_used'] }} / {{ $bots['formatted_limit'] }}</span>
                      </div>
                      <div class="mt-1.5 h-1 overflow-hidden rounded-full bg-[#303540]">
                        <div class="h-full rounded-full {{ $botPercent >= 80 ? 'bg-[#D47B78]' : 'bg-[#FCB900]' }}" style="width: {{ $botPercent }}%"></div>
                      </div>
                      @include('partials.billing-limit-equation', ['equation' => $billingTerms->billingMetricEquation($billing, $bots, 'bot_fair_use'), 'class' => 'mt-1'])
                    </div>
                  </div>
                @else
                  <span class="inline-flex items-center gap-2 rounded-md border border-[#FCB900]/24 bg-[#FCB900]/10 px-2.5 py-1 text-xs font-bold text-[#FFDC9C]">
                    <img src="{{ asset('duotone/triangle-exclamation.svg') }}" alt="" class="es-duotone-icon es-icon-tone-brass h-3.5 w-3.5">
                    Run billing migrations first
                  </span>
                @endif
              </td>
              <td class="px-4 py-4 align-top">
                @if($grant)
                  <div class="min-w-[8rem]">
                    <span class="inline-flex rounded-md border border-[#FCB900]/24 bg-[#FCB900]/10 px-2 py-1 text-xs font-black text-[#FFDC9C]">{{ strtoupper($grant['granted_plan_key']) }}</span>
                    <div class="mt-1.5 max-w-[10rem] text-xs leading-5 text-[#AEB9CC]">{{ $grant['reason'] ?: 'Bonus allowance' }}</div>
                    <form method="POST" action="{{ route('admin.tenants.manual_grants.revoke', [$tenant, $grant['id']]) }}" class="mt-2">
                      @csrf
                      <button class="inline-flex items-center gap-1.5 text-xs font-bold text-[#FFE6E3] hover:text-[#FFFFFF]" type="submit">
                        <img src="{{ asset('duotone/trash.svg') }}" alt="" class="es-duotone-icon es-icon-tone-coral h-3.5 w-3.5">
                        Revoke Bonus
                      </button>
                    </form>
                  </div>
                @else
                  <span class="inline-flex rounded-full border border-[#303540] bg-[#252A34] px-2.5 py-1 text-xs font-bold text-[#7F8BA0]">None</span>
                @endif
              </td>
              <td class="px-4 py-4 align-top">
                <div class="min-w-[6rem]">
                  <div class="flex items-center gap-2 font-bold text-[#FFFFFF]">
                    <img src="{{ asset('duotone/spider-web.svg') }}" alt="" class="es-duotone-icon es-icon-tone-brass h-4 w-4">
                    {{ number_format($row['domains_count']) }}
                  </div>
                  <div class="mt-1 text-xs text-[#AEB9CC]">Domains</div>
                  <div class="mt-1 text-xs text-[#7F8BA0]">{{ $row['members_count'] ?? 0 }} workspace user(s)</div>
                </div>
              </td>
              <td class="px-4 py-4 align-top">
                <div class="min-w-[9rem] space-y-1 text-xs">
                  <div class="flex items-center justify-between gap-3">
                    <span class="text-[#7F8BA0]">Estimate</span>
                    <span class="font-bold text-[#FFFFFF]">${{ number_format((float) ($cf['summary']['estimated_cost_usd'] ?? 0), 2) }}</span>
                  </div>
                  <div class="flex items-center justify-between gap-3">
                    <span class="text-[#AEB9CC]">Revenue</span>
                    <span class="font-mono text-[#D7E1F5]">${{ number_format((float) ($cf['monthly_revenue_usd'] ?? 0), 2) }}</span>
                  </div>
                  <div class="flex items-center justify-between gap-3">
                    <span class="{{ $profit < 0 ? 'text-[#FFE6E3]' : 'text-[#FFDC9C]' }}">Profit</span>
                    <span class="font-mono {{ $profit < 0 ? 'text-[#FFE6E3]' : 'text-[#FFDC9C]' }}">
                      ${{ number_format($profit, 2) }}
                      @if(($cf['margin_percentage'] ?? null) !== null)
                        / {{ $cf['margin_percentage'] }}%
                      @endif
                    </span>
                  </div>
                  <div class="pt-1">
                    <span class="inline-flex rounded-full border {{ $tenant->is_vip ? 'border-[#FCB900]/24 bg-[#FCB900]/10 text-[#FFDC9C]' : 'border-[#303540] bg-[#252A34] text-[#7F8BA0]' }} px-2 py-0.5 text-[11px] font-bold">
                      {{ $tenant->is_vip ? 'VIP visible' : 'Hidden from customer' }}
                    </span>
                  </div>
                </div>
              </td>
              <td class="px-4 py-4 align-top">
                <div class="min-w-[16rem]">
                  <div class="flex flex-wrap gap-1.5">
                    <a href="{{ route('admin.tenants.show', $tenant) }}" class="es-btn es-btn-secondary min-h-0 px-2.5 py-1.5 text-xs">
                      <img src="{{ asset('duotone/user-shield.svg') }}" alt="" class="es-duotone-icon es-icon-tone-muted h-3.5 w-3.5">
                      Manage User
                    </a>
                    <form method="POST" action="{{ route('admin.tenants.force_cycle_reset', $tenant) }}">
                      @csrf
                      <button class="es-btn min-h-0 px-2.5 py-1.5 text-xs" type="submit">
                        <img src="{{ asset('duotone/arrows-rotate.svg') }}" alt="" class="h-3.5 w-3.5 opacity-75" style="filter: brightness(0);">
                        Force Reset
                      </button>
                    </form>
                    <form method="POST" action="{{ route('admin.tenants.vip.update', $tenant) }}">
                      @csrf
                      <input type="hidden" name="is_vip" value="{{ $tenant->is_vip ? 0 : 1 }}">
                      <button class="es-btn es-btn-secondary min-h-0 px-2.5 py-1.5 text-xs" type="submit">
                        <img src="{{ asset('duotone/cloud-check.svg') }}" alt="" class="es-duotone-icon es-icon-tone-muted h-3.5 w-3.5">
                        {{ $tenant->is_vip ? 'Hide Cost' : 'VIP Cost' }}
                      </button>
                    </form>
                  </div>
                  @if($billingAvailable)
                    <form method="POST" action="{{ route('admin.tenants.manual_grants.store', $tenant) }}" class="mt-2 grid gap-1.5">
                      @csrf
                      <div class="grid grid-cols-[minmax(0,1fr)_4.5rem] gap-1.5">
                        <select name="plan_key" class="es-input h-8 text-xs">
                          @foreach($grantablePlans as $plan)
                            <option value="{{ $plan['key'] }}">{{ $plan['name'] }}</option>
                          @endforeach
                        </select>
                        <input name="duration_days" class="es-input h-8 text-xs" type="number" min="1" max="365" value="14">
                      </div>
                      <div class="grid grid-cols-[minmax(0,1fr)_5.5rem] gap-1.5">
                        <input name="reason" class="es-input h-8 text-xs" placeholder="Reason">
                        <button class="es-btn es-btn-soft-warning min-h-0 px-2 py-1.5 text-xs" type="submit">
                          <img src="{{ asset('duotone/circle-plus.svg') }}" alt="" class="es-duotone-icon es-icon-tone-brass h-3.5 w-3.5">
                          Add Bonus
                        </button>
                      </div>
                    </form>
                  @endif
                </div>
              </td>
            </tr>
            <tr aria-hidden="true" class="bg-[#0E131D]">
              <td colspan="8" class="p-0">
                <hr class="border-0 border-t border-[#303540]">
              </td>
            </tr>
          @empty
            <tr>
              <td colspan="8" class="px-4 py-12 text-center">
                <div class="mx-auto flex max-w-sm flex-col items-center gap-3">
                  <span class="inline-flex h-12 w-12 items-center justify-center rounded-lg border border-[#303540] bg-[#252A34]">
                    <img src="{{ asset('duotone/user-secret.svg') }}" alt="" class="es-duotone-icon es-icon-tone-muted h-5 w-5">
                  </span>
                  <div class="font-bold text-[#FFFFFF]">No users found.</div>
                </div>
              </td>
            </tr>
          @endforelse
          </tbody>
        </table>
      </div>
    </div>

    @if(isset($tenants))
      <div class="mt-4">{{ $tenants->links() }}</div>
    @endif
  </section>
@endsection
