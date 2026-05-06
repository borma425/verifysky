@extends('layouts.admin')

@section('content')
  <div class="mb-6 flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
    <div>
      <a href="{{ route('admin.tenants.show', $tenant) }}" class="text-sm font-semibold text-cyan-200 hover:text-cyan-100">Back to {{ $tenant->name }}</a>
      <h1 class="es-title mt-2">Protected Paths</h1>
      <p class="es-subtitle mt-2">Protect important URLs for all domains or one domain.</p>
    </div>
    <div class="flex gap-2">
      <a href="{{ route('admin.tenants.firewall.index', $tenant) }}" class="es-btn es-btn-secondary">Firewall</a>
      <a href="{{ route('admin.tenants.ip_farm.index', $tenant) }}" class="es-btn es-btn-secondary">Blocked IPs</a>
    </div>
  </div>

  @if(!empty($loadErrors))
    <div class="mb-4 rounded-xl border border-amber-400/35 bg-amber-500/15 px-4 py-3 text-sm text-amber-100">
      @foreach($loadErrors as $msg)
        <div>{{ $msg }}</div>
      @endforeach
    </div>
  @endif

  <div class="grid gap-5 xl:grid-cols-[420px_1fr]">
    <div class="es-card p-5">
      <h2 class="mb-4 text-lg font-bold text-white">Add protected path</h2>
      <form method="POST" action="{{ route('admin.tenants.sensitive_paths.store', $tenant) }}" class="space-y-3">
        @csrf
        <label class="block text-sm text-sky-100">Scope
          <select class="es-input mt-1" name="scope">
            <option value="tenant">All domains</option>
            <option value="domain">Specific domain</option>
          </select>
        </label>
        <label class="block text-sm text-sky-100">Domain
          <select class="es-input mt-1" name="domain_name">
            @foreach($domainRecords as $domain)
              <option value="{{ $domain->hostname }}">{{ $domain->hostname }}</option>
            @endforeach
          </select>
        </label>
        <input class="es-input" name="path_pattern" placeholder="/wp-login.php" required>
        <div class="grid gap-3 md:grid-cols-2">
          <select class="es-input" name="match_type">
            <option value="exact">exact</option>
            <option value="contains">contains</option>
            <option value="ends_with">ends with</option>
          </select>
          <select class="es-input" name="action">
            <option value="block">block</option>
            <option value="challenge">challenge</option>
          </select>
        </div>
        <button class="es-btn w-full" type="submit">Save protected path</button>
      </form>
    </div>

    <div class="es-card p-0">
      <div class="border-b border-white/10 p-5">
        <h2 class="text-lg font-bold text-white">Protected paths</h2>
      </div>
      <div class="overflow-x-auto">
        <table class="es-table min-w-[900px]">
          <thead>
          <tr>
            <th>ID</th>
            <th>Scope</th>
            <th>Domain</th>
            <th>Path</th>
            <th>Match</th>
            <th>Action</th>
            <th>Delete</th>
          </tr>
          </thead>
          <tbody>
          @forelse($paths as $path)
            @php $scope = ($path['scope'] ?? '') === 'tenant' || ($path['domain_name'] ?? '') === 'global' ? 'tenant' : 'domain'; @endphp
            <tr>
              <td>#{{ $path['id'] ?? '' }}</td>
              <td>{{ $scope === 'tenant' ? 'All domains' : 'Specific domain' }}</td>
              <td>{{ $scope === 'tenant' ? 'All domains' : ($path['domain_name'] ?? '') }}</td>
              <td class="font-mono text-sky-100">{{ $path['path_pattern'] ?? '' }}</td>
              <td>{{ $path['match_type'] ?? '' }}</td>
              <td>{{ $path['action'] ?? '' }}</td>
              <td>
                <form method="POST" action="{{ route('admin.tenants.sensitive_paths.destroy', [$tenant, (int) ($path['id'] ?? 0)]) }}">
                  @csrf
                  @method('DELETE')
                  <button class="text-sm font-semibold text-rose-200 hover:text-rose-100" type="submit">Delete</button>
                </form>
              </td>
            </tr>
          @empty
            <tr><td colspan="7" class="py-8 text-center text-sky-100/70">No protected paths for this user.</td></tr>
          @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div>
@endsection
