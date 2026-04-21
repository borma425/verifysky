@foreach($firewallRules as $rule)
  <form id="toggle-form-{{ $rule['id'] }}" method="POST" action="{{ route('firewall.toggle', ['domain' => $rule['domain_name'], 'ruleId' => $rule['id']]) }}" class="hidden">
    @csrf
    <input type="hidden" name="paused" value="{{ $rule['next_paused_value'] }}">
  </form>
@endforeach
