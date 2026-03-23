@extends('layouts.app')

@section('content')
  <div class="es-card es-animate mb-4 p-5 md:p-6">
    <h2 class="es-title mb-1">Trap Leads</h2>
    <p class="es-subtitle">Subscription requests submitted through the marketing landing page.</p>
  </div>

  <form method="GET" class="es-card es-animate es-animate-delay mb-4 p-4">
    <div class="flex gap-2">
      <input name="q" value="{{ $search }}" placeholder="Search by name / email / domain / IP" class="es-input">
      <button class="es-btn" type="submit">Search</button>
    </div>
  </form>

  <div class="es-card es-animate es-animate-delay-2 p-5 md:p-6">
    <div class="overflow-x-auto">
      <table class="es-table min-w-full">
        <thead>
          <tr>
            <th>#</th>
            <th>Name</th>
            <th>Email</th>
            <th>Domain</th>
            <th>Company</th>
            <th>IP</th>
            <th>Submitted At</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          @forelse($leads as $lead)
            <tr>
              <td>{{ $lead->id }}</td>
              <td>{{ $lead->name }}</td>
              <td>{{ $lead->email }}</td>
              <td>{{ $lead->domain }}</td>
              <td>{{ $lead->company ?: '—' }}</td>
              <td>{{ $lead->ip_address ?: '—' }}</td>
              <td>{{ $lead->created_at }}</td>
              <td>
                <form method="POST" action="{{ route('trap_network.destroy', $lead) }}" onsubmit="return confirm('Delete this record?');">
                  @csrf
                  @method('DELETE')
                  <button type="submit" class="es-btn es-btn-danger">Delete</button>
                </form>
              </td>
            </tr>
            @if($lead->notes)
              <tr>
                <td colspan="8" class="text-slate-300"><strong>Notes:</strong> {{ $lead->notes }}</td>
              </tr>
            @endif
          @empty
            <tr>
              <td colspan="8" class="text-center text-slate-300">No records yet.</td>
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
