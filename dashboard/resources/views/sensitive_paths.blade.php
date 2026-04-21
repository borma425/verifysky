@extends('layouts.app')

@section('content')
  <div class="mb-4 flex items-center justify-between">
    <h2 class="es-title text-2xl">Sensitive Paths Protection</h2>
  </div>

  @if(!empty($loadErrors))
    <div class="mb-4 rounded-xl border border-amber-400/35 bg-amber-500/15 px-4 py-3 text-sm text-amber-100">
      @foreach($loadErrors as $msg)
        <div>{{ $msg }}</div>
      @endforeach
    </div>
  @endif

  @include('sensitive_paths.partials.create-form')

  <div class="grid grid-cols-1 xl:grid-cols-2 gap-6 items-start" data-destroy-base="{{ url('/sensitive-paths') }}">
    @include('sensitive_paths.partials.critical-table')
    @include('sensitive_paths.partials.medium-table')
  </div>

  <form id="singleUnlockForm" method="POST" action="" class="hidden">
    @csrf
    @method('DELETE')
  </form>
@endsection
