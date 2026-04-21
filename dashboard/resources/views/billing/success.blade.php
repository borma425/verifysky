@extends('layouts.app')

@section('content')
  <section class="es-animate">
    <div class="es-card max-w-3xl p-6 md:p-8">
      <div class="flex items-center gap-3">
        <img src="{{ asset('duotone/circle-check.svg') }}" alt="success" class="es-duotone-icon es-icon-tone-success h-6 w-6">
        <div>
          <p class="text-[10px] uppercase tracking-[0.22em] text-[#76859C]">Checkout Returned</p>
          <h1 class="text-2xl font-extrabold text-[#FFFFFF]">Waiting For PayPal Confirmation</h1>
        </div>
      </div>

      <p class="mt-5 text-sm leading-7 text-[#D7E1F5]">
        Your checkout returned successfully. VerifySky will switch plans only after the PayPal webhook confirms the subscription activation or renewal.
      </p>

      @if($subscription)
        <div class="mt-5 rounded-xl border border-white/10 bg-[#202632] px-4 py-4 text-sm text-[#D7E1F5]">
          <div class="font-bold text-[#FFFFFF]">Latest subscription reference</div>
          <div class="mt-2 font-mono text-xs">{{ $subscription->provider_subscription_id }}</div>
          <div class="mt-2 text-[#AEB9CC]">Current state: {{ ucfirst(str_replace('_', ' ', $subscription->status)) }}</div>
        </div>
      @endif

      <div class="mt-6 flex flex-wrap gap-3">
        <a href="{{ route('billing.index') }}" class="es-btn">Back To Billing</a>
        <a href="{{ route('dashboard') }}" class="es-btn es-btn-secondary">Return To Dashboard</a>
      </div>
    </div>
  </section>
@endsection
