@extends('layouts.app')

@section('content')
  <div class="mb-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
    <h2 class="mb-1 text-xl font-semibold">Real Scammers</h2>
    <p class="text-sm text-slate-500">Subscription requests submitted through the marketing landing page.</p>
  </div>

  <form method="GET" class="mb-4 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
    <div class="flex gap-2">
      <input name="q" value="{{ $search }}" placeholder="Search by name / email / domain / IP" class="w-full rounded-lg border border-slate-300 px-3 py-2">
      <button class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white">Search</button>
    </div>
  </form>

  <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
    <div class="overflow-x-auto">
      <table class="min-w-full text-sm">
        <thead class="bg-slate-50 text-left text-slate-600">
          <tr>
            <th class="px-3 py-2">#</th>
            <th class="px-3 py-2">Name</th>
            <th class="px-3 py-2">Email</th>
            <th class="px-3 py-2">Domain</th>
            <th class="px-3 py-2">Company</th>
            <th class="px-3 py-2">IP</th>
            <th class="px-3 py-2">Submitted At</th>
            <th class="px-3 py-2">Action</th>
          </tr>
        </thead>
        <tbody>
          @forelse($leads as $lead)
            <tr class="border-b border-slate-100">
              <td class="px-3 py-2">{{ $lead->id }}</td>
              <td class="px-3 py-2">{{ $lead->name }}</td>
              <td class="px-3 py-2">{{ $lead->email }}</td>
              <td class="px-3 py-2">{{ $lead->domain }}</td>
              <td class="px-3 py-2">{{ $lead->company ?: '—' }}</td>
              <td class="px-3 py-2">{{ $lead->ip_address ?: '—' }}</td>
              <td class="px-3 py-2">{{ $lead->created_at }}</td>
              <td class="px-3 py-2">
                <form method="POST" action="{{ route('trap_network.destroy', $lead) }}" onsubmit="return confirm('Delete this record?');">
                  @csrf
                  @method('DELETE')
                  <button type="submit" class="rounded-md bg-rose-600 px-3 py-1 text-xs font-semibold text-white hover:bg-rose-500">Delete</button>
                </form>
              </td>
            </tr>
            @if($lead->notes)
              <tr class="border-b border-slate-100 bg-slate-50/70">
                <td colspan="8" class="px-3 py-2 text-slate-600"><strong>Notes:</strong> {{ $lead->notes }}</td>
              </tr>
            @endif
          @empty
            <tr>
              <td colspan="8" class="px-3 py-4 text-center text-slate-500">No records yet.</td>
            </tr>
          @endforelse
        </tbody>
      </table>
    </div>
    <div class="mt-4">
      {{ $leads->links() }}
    </div>
  </div>
@endsection
