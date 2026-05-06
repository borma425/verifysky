@extends('layouts.app')

@section('content')
  <div class="mx-auto w-full max-w-[1600px] space-y-4">
    <div class="flex items-center justify-between">
      <h2 class="text-2xl font-semibold leading-8 tracking-normal text-white">Protected Paths</h2>
    </div>

    @if(!empty($loadErrors))
      <div class="flex items-start gap-3 rounded-lg border border-[#D47B78]/30 bg-[#D47B78]/10 p-4 text-sm text-[#FFB4AB]">
        <img src="{{ asset('duotone/circle-exclamation.svg') }}" alt="" class="es-duotone-icon es-icon-tone-coral h-5 w-5 shrink-0">
        <div class="min-w-0 space-y-1 font-mono text-xs leading-5 text-[#FFB4AB]/85">
          @foreach($loadErrors as $msg)
            <div class="break-words">&gt; {{ $msg }}</div>
          @endforeach
        </div>
      </div>
    @endif

    @include('sensitive_paths.partials.create-form')

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2" data-destroy-base="{{ url('/sensitive-paths') }}">
      @include('sensitive_paths.partials.critical-table')
      @include('sensitive_paths.partials.medium-table')
    </div>

    <form id="singleUnlockForm" method="POST" action="" class="hidden">
      @csrf
      @method('DELETE')
    </form>
  </div>
@endsection
