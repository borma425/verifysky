<div class="es-domain-panel p-5 shadow-inner">
  <h4 class="mb-4 text-[11px] font-bold tracking-[0.22em] uppercase text-[#D7E1F5]">{{ $group['primary_verified'] ? 'Live Status' : 'Status Checks' }}</h4>
  <div class="space-y-2">
    @foreach($group['health_rows'] as $row)
      <div class="grid h-full grid-cols-2 gap-3">
        <div class="flex flex-col items-center justify-center rounded-xl border {{ $row['hostname_status_class'] }} p-3 text-center transition-colors">
          <span class="mb-2 block w-full border-b border-white/5 pb-2 text-[10px] font-bold uppercase tracking-[0.18em] text-[#959BA7]">DNS Record</span>
          <span class="mt-1 text-sm font-semibold capitalize">{{ $row['hostname_status_normalized'] }}</span>
        </div>
        <div class="flex flex-col items-center justify-center rounded-xl border {{ $row['ssl_status_class'] }} p-3 text-center transition-colors">
          <span class="mb-2 block w-full border-b border-white/5 pb-2 text-[10px] font-bold uppercase tracking-[0.18em] text-[#959BA7]">SSL Cert</span>
          <span class="mt-1 text-sm font-semibold capitalize">{{ $row['ssl_status_label'] }}</span>
        </div>
      </div>
    @endforeach
  </div>
</div>
