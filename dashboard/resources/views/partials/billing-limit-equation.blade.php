@if(! empty($equation))
  <div class="{{ $class ?? 'mt-2' }} flex flex-wrap items-center gap-1.5 text-[11px] font-semibold text-[#AEB9CC]">
    <span>Plan Limit: <span class="text-[#D7E1F5]">{{ $equation['base'] }}</span></span>
    <span class="text-[#76859C]">+</span>
    <span>Bonus: <span class="{{ $equation['has_bonus'] ? 'text-[#FCB900]' : 'text-[#D7E1F5]' }}">{{ $equation['bonus'] }}</span></span>
    <span class="text-[#76859C]">=</span>
    <span>Total Limit: <span class="text-[#FFFFFF]">{{ $equation['total'] }}</span></span>
  </div>
@endif
