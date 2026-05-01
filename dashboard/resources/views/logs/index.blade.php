@extends('layouts.app')

@section('content')
  <div class="vs-logs-page">
    @include('logs.partials.filters')
    @include('logs.partials.stats')
    @include('logs.partials.table')
  </div>
@endsection
