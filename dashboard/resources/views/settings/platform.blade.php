@extends($layout ?? 'layouts.admin')

@section('content')
  <div class="es-card es-animate p-5 md:p-6">
    <h2 class="es-title mb-2">Platform Settings</h2>

    <div class="mt-4 grid gap-3 md:grid-cols-2">
      @foreach($platformSettings as $setting)
        <div class="es-card-soft px-3 py-2">
          <div class="text-xs uppercase tracking-[0.16em] text-[#7F8BA0]">{{ $setting['label'] }}</div>
          <div class="mt-1 text-sm font-semibold text-sky-100">
            @if($setting['secret'] ?? false)
              {{ ($setting['configured'] ?? false) ? 'Configured' : 'Missing' }}
            @else
              {{ trim((string) ($setting['value'] ?? '')) !== '' ? $setting['value'] : 'Not configured' }}
            @endif
          </div>
        </div>
      @endforeach
    </div>

    <form method="POST" action="{{ route($settingsUpdateRoute ?? 'admin.settings.update') }}" class="mt-4">
      @csrf
      <button class="es-btn" type="submit">Sync Edge Runtime</button>
    </form>
  </div>
@endsection
