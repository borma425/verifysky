@extends('layouts.customer-mirror')

@section('content')
  <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
    <div class="es-card p-5">
      <div class="text-[10px] uppercase tracking-[0.18em] text-[#76859C]">Plan</div>
      <div class="mt-2 text-xl font-bold text-white">{{ $currentPlan['name'] ?? 'Starter' }}</div>
    </div>
    <div class="es-card p-5">
      <div class="text-[10px] uppercase tracking-[0.18em] text-[#76859C]">Protected Domains</div>
      <div class="mt-2 text-xl font-bold text-white">{{ number_format($domainsCount) }}</div>
    </div>
    <div class="es-card p-5">
      <div class="text-[10px] uppercase tracking-[0.18em] text-[#76859C]">Protected Sessions</div>
      <div class="mt-2 text-xl font-bold text-white">{{ $billingStatus['protected_sessions']['formatted_used'] ?? '0' }}</div>
      <div class="mt-1 text-xs text-sky-100/65">{{ $billingStatus['protected_sessions']['formatted_limit'] ?? '0' }} limit</div>
    </div>
    <div class="es-card p-5">
      <div class="text-[10px] uppercase tracking-[0.18em] text-[#76859C]">Bot Fair Use</div>
      <div class="mt-2 text-xl font-bold text-white">{{ $billingStatus['bot_requests']['formatted_used'] ?? '0' }}</div>
      <div class="mt-1 text-xs text-sky-100/65">{{ $billingStatus['bot_requests']['formatted_limit'] ?? '0' }} limit</div>
    </div>
  </div>

  @include('logs.partials.stats')

  <div class="es-card p-0">
    <div class="border-b border-white/10 p-5">
      <h2 class="text-lg font-bold text-white">Recent Security Events</h2>
      <p class="mt-1 text-sm text-sky-100/65">Latest tenant-scoped events from the customer-facing security feed.</p>
    </div>
    <div class="overflow-x-auto">
      <table class="es-table min-w-[980px]">
        <thead>
        <tr>
          <th>Domain</th>
          <th>Event</th>
          <th>IP</th>
          <th>Attacks</th>
          <th>Country</th>
          <th>Time</th>
        </tr>
        </thead>
        <tbody>
        @forelse($recentLogs as $row)
          <tr>
            <td>{{ $row['domain'] ?? '-' }}</td>
            <td>{{ $row['event_display'] ?? '-' }}</td>
            <td>{{ $row['ip_address'] ?? '-' }}</td>
            <td>{{ $row['requests_today'] ?? 0 }}</td>
            <td>{{ $row['country'] ?? '-' }}</td>
            <td>{{ $row['created_at'] ?? '-' }}</td>
          </tr>
        @empty
          <tr>
            <td colspan="6" class="py-8 text-center text-sky-100/70">No recent tenant events.</td>
          </tr>
        @endforelse
        </tbody>
      </table>
    </div>
  </div>
@endsection
