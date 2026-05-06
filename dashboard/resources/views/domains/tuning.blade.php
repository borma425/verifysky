@extends('layouts.app')

@section('content')
  <div
    class="vs-tuning-shell"
    x-data="domainTuning"
    data-domain="{{ $domain }}"
    data-active-challenge-mode="{{ $activeChallengeMode }}"
  >
    <script type="application/json" id="domain-tuning-thresholds">@json($clientState['thresholds'])</script>
    <script type="application/json" id="domain-tuning-challenge-profiles">@json($clientState['challengeProfiles'])</script>

    <div class="mb-6">
      <a href="{{ route('domains.index') }}" class="vs-tuning-button vs-tuning-button-muted">&larr; Back to Domains</a>
    </div>

    <div class="vs-tuning-topline">
      <div>
        <div class="vs-tuning-eyebrow">Domain Protection</div>
        <h1 class="vs-tuning-title">Protection settings for {{ $domain }}</h1>
        <p class="vs-tuning-subtitle">Change security settings instantly<br>No deploy needed</p>
      </div>
      <div class="vs-tuning-runtime">Safe controls. Changes are applied through existing forms.</div>
    </div>

    @include('domains.partials.tuning.notice')
    @include('domains.partials.tuning.alerts')

    <div class="vs-tuning-grid vs-tuning-top-grid">
      @include('domains.partials.tuning.analyzer')
      @include('domains.partials.tuning.origin-form')
    </div>

    @include('domains.partials.tuning.threshold-form')
  </div>
@endsection
