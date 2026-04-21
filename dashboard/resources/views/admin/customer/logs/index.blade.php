@extends('layouts.customer-mirror')

@section('content')
  @php
    $logsIndexRoute = route('admin.tenants.customer.logs.index', $tenant);
    $logsResetRoute = route('admin.tenants.customer.logs.index', $tenant);
  @endphp

  @include('logs.partials.stats')
  @include('logs.partials.filters')
  @include('logs.partials.table')
@endsection
