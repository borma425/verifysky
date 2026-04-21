@extends('layouts.app')

@section('content')
  @include('logs.partials.stats')
  @include('logs.partials.filters')
  @include('logs.partials.table')
@endsection
