<div class="es-domain-panel p-5 shadow-inner">
  <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
    <div>
      <h4 class="text-[11px] font-bold uppercase tracking-[0.22em] text-[#D7E1F5]">Connected</h4>
      <p class="mt-2 max-w-xl text-sm leading-relaxed text-[#D7E1F5]">This domain is already routed through VerifySky. DNS instructions are hidden here so the list stays focused on active state and controls.</p>
    </div>
    <div class="rounded-xl border border-[#FCB900]/20 bg-[#FCB900]/10 px-4 py-3 text-sm text-[#FFFFFF]">
      Edge route verified for <span class="font-mono">{{ $group['primary_domain'] }}</span>
    </div>
  </div>

  <div class="mt-5 grid gap-3 sm:grid-cols-3">
    <div class="es-domain-subpanel px-4 py-3.5">
      <div class="text-[11px] font-bold uppercase tracking-[0.18em] text-[#959BA7]">Customer Domain</div>
      <div class="mt-1.5 text-sm font-semibold text-white">{{ $group['display_domain'] }}</div>
    </div>
    <div class="es-domain-subpanel px-4 py-3.5">
      <div class="text-[11px] font-bold uppercase tracking-[0.18em] text-[#959BA7]">Edge Hostname</div>
      <div class="mt-1.5 text-sm font-mono text-[#D7E1F5] break-all">{{ $group['primary_domain'] }}</div>
    </div>
    <div class="es-domain-subpanel px-4 py-3.5">
      <div class="text-[11px] font-bold uppercase tracking-[0.18em] text-[#959BA7]">Protection Mode</div>
      <div class="mt-1.5 text-sm font-semibold capitalize text-[#FFFFFF]">{{ $group['mode'] }}</div>
    </div>
  </div>
</div>
