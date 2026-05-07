@php
  $billingTerms = $billingTerms ?? app(\App\ViewData\BillingTerminologyViewData::class);
  $trialGrant = $trialGrant ?? null;
  $trialEndsAt = is_array($trialGrant)
      ? ($trialGrant['ends_at'] ?? null)
      : ($trialGrant?->ends_at ?? null);
  $trialPlanKey = is_array($trialGrant)
      ? (string) ($trialGrant['granted_plan_key'] ?? 'pro')
      : (string) ($trialGrant?->granted_plan_key ?? 'pro');
  $trialPlanName = $trialPlanName ?? ucfirst($trialPlanKey);
  $trialDaysRemaining = $billingTerms->daysRemaining($trialEndsAt);
@endphp

<div class="rounded-2xl border border-[#58D68D]/25 bg-[#58D68D]/10 p-4 text-sm text-[#E8FFF1] shadow-sm backdrop-blur-md">
  <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
    <div class="flex items-start gap-3">
      <span class="mt-0.5 inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border border-[#58D68D]/20 bg-[#171C26]">
        <img src="{{ asset('duotone/shield-check.svg') }}" alt="" class="es-duotone-icon es-icon-tone-success h-5 w-5">
      </span>
      <div>
        <div class="text-[11px] font-bold uppercase tracking-[0.18em] text-[#58D68D]">Pro trial active</div>
        <div class="mt-1 font-semibold text-white">
          {{ $trialPlanName }} protection is active until
          <span class="font-mono">{{ $trialEndsAt?->format('Y-m-d H:i') }} UTC</span>.
        </div>
        <div class="mt-1 text-xs text-[#BEEFD0]">
          {{ $trialDaysRemaining }} {{ $trialDaysRemaining === 1 ? 'day' : 'days' }} remaining. Upgrade before the trial ends to keep these limits.
        </div>
      </div>
    </div>
    <a href="{{ route('billing.index') }}" class="es-btn shrink-0 px-4 py-2 text-sm">Upgrade to keep Pro</a>
  </div>
</div>
