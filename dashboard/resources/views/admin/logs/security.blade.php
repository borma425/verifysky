@extends('layouts.admin')

@section('content')
  <div class="mb-5 flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
    <div>
      <h1 class="es-title">System Logs</h1>
      <p class="es-subtitle mt-2">D1 security telemetry across the platform.</p>
    </div>
    <div class="flex gap-2">
      <a href="{{ route('admin.logs.security') }}" class="es-btn">Security Logs</a>
      <a href="{{ route('admin.logs.platform') }}" class="es-btn es-btn-secondary">Platform Logs</a>
    </div>
  </div>

  @php($logsIndexRoute = route('admin.logs.security'))
  @php($logsResetRoute = route('admin.logs.security'))
  @include('logs.partials.stats')
  @include('logs.partials.filters')
  @include('logs.partials.table')
@endsection
