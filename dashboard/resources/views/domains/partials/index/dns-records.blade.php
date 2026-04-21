<div class="es-domain-panel p-4 shadow-inner">
  <div class="mb-4">
    <h4 class="text-xs font-bold tracking-widest uppercase text-[#FCB900]">{{ $group['primary_verified'] ? 'DNS Details' : 'Action Required' }}</h4>
    <p class="mt-1.5 text-[11px] text-[#D7E1F5]">
      {{ $group['primary_verified'] ? 'Edge routing is successfully verified to VerifySky infrastructure.' : 'Create this DNS record at your registrar for edge routing.' }}
    </p>
  </div>

  <div class="space-y-2.5">
    @foreach($group['dns_rows'] as $row)
      <div class="flex flex-col rounded-md border border-white/5 bg-[#202633] p-1.5 divide-y divide-white/10 sm:flex-row sm:items-center sm:divide-x sm:divide-y-0">
        <div class="shrink-0 px-3 py-1.5 sm:w-20">
          <div class="font-mono text-xs font-bold text-[#FCB900]">{{ $row['record_type'] }}</div>
        </div>
        <div class="flex min-w-0 flex-1 items-center justify-between px-3 py-1.5">
          <div class="max-w-[120px] truncate font-mono text-xs text-white">{{ $row['record_name'] }}</div>
          <button type="button" x-on:click="copy(@js($row['record_name']), 'host-{{ $groupIndex }}-{{ $loop->index }}')" class="shrink-0 rounded bg-white/5 p-1.5 text-[#959BA7] transition-colors hover:bg-[#FCB900]/20 hover:text-[#FCB900]">Copy</button>
        </div>
        <div class="flex min-w-0 flex-1 items-center justify-between px-3 py-1.5">
          <div class="truncate font-mono text-xs text-[#D7E1F5]">{{ $row['target'] }}</div>
          <button type="button" x-on:click="copy(@js($row['target']), 'target-{{ $groupIndex }}-{{ $loop->index }}')" class="shrink-0 rounded bg-white/5 p-1.5 text-[#959BA7] transition-colors hover:bg-[#FCB900]/20 hover:text-[#FCB900]">Copy</button>
        </div>
      </div>
    @endforeach
  </div>
</div>
