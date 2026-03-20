@extends('layouts.app')

@section('content')
  <div class="mb-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
    <h2 class="mb-2 text-xl font-semibold">Operational Actions</h2>
    <p class="mb-4 text-sm text-slate-500">Run guarded operational commands against the Edge Shield worker project.</p>
    <form method="POST" action="{{ route('actions.run') }}">
      @csrf
      <div class="grid gap-3 md:grid-cols-2">
        <div>
          <label class="mb-1 block text-sm text-slate-600">Action</label>
          <select class="w-full rounded-lg border border-slate-300 px-3 py-2" name="action">
            <option value="typecheck">Typecheck</option>
            <option value="build">Build (dry-run deploy)</option>
            <option value="deploy">Deploy Worker</option>
            <option value="db_init_remote">Init Remote D1 Schema</option>
            <option value="db_init_local">Init Local D1 Schema</option>
            <option value="optimize_clear">Optimize Clear</option>
          </select>
        </div>
        <div class="flex items-end">
          <button class="rounded-lg bg-sky-500 px-4 py-2 text-sm font-semibold text-white hover:bg-sky-400" type="submit">Run Action</button>
        </div>
      </div>
    </form>
  </div>

  @php($result = session('action_result'))
  @if($result)
    <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
      <h3 class="mb-3 text-lg font-semibold">Last Action Result</h3>
      <p class="text-sm"><strong>Action:</strong> {{ $result['action'] }}</p>
      <p class="mb-3 text-sm"><strong>Status:</strong> {{ $result['ok'] ? 'Success' : 'Failed' }} (exit={{ $result['exit_code'] }})</p>
      <h4 class="mb-1 text-sm font-semibold text-slate-600">Output</h4>
      <div class="mb-3 whitespace-pre-wrap rounded-xl bg-slate-950 p-3 text-xs text-sky-100">{{ $result['output'] ?: '(empty)' }}</div>
      <h4 class="mb-1 text-sm font-semibold text-slate-600">Error</h4>
      <div class="whitespace-pre-wrap rounded-xl bg-slate-950 p-3 text-xs text-rose-200">{{ $result['error'] ?: '(empty)' }}</div>
    </div>
  @endif
@endsection
