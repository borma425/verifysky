@extends('layouts.app')

@section('content')
  <div class="mb-4">
    <a href="{{ route('domains.index') }}" class="es-btn es-btn-secondary text-sm">&larr; Back to Domains</a>
  </div>

  <div
    x-data="domainTuning"
    data-domain="{{ $domain }}"
    data-active-challenge-mode="{{ $activeChallengeMode }}"
  >
    <script type="application/json" id="domain-tuning-thresholds">@json($clientState['thresholds'])</script>
    <script type="application/json" id="domain-tuning-challenge-profiles">@json($clientState['challengeProfiles'])</script>

    <div class="es-card es-animate mb-4 p-5 md:p-6">
      <h2 class="es-title mb-3">Protection Tuning for {{ $domain }}</h2>
      <p class="es-subtitle mb-3">Control security instantly<br>No deploy needed</p>

      @include('domains.partials.tuning.notice')
      @include('domains.partials.tuning.alerts')
      @include('domains.partials.tuning.analyzer')
      @include('domains.partials.tuning.origin-form')
      @include('domains.partials.tuning.threshold-form')
    </div>
  </div>
@endsection
