@php($headline = 'New customer access created')
@php($subject = 'New customer access has been created')
@extends('emails.layouts.branded')

@section('content')
  <p style="margin:0 0 16px;font-size:15px;line-height:1.7;">
    A non-admin customer account has been created for <strong>{{ $user->name }}</strong> ({{ $user->email }}).
  </p>

  @if($tenantNames !== [])
    <p style="margin:0 0 8px;font-size:14px;color:#94a3b8;">Assigned client accounts:</p>
    <ul style="margin:0 0 18px 20px;padding:0;">
      @foreach($tenantNames as $tenantName)
        <li style="margin:0 0 6px;">{{ $tenantName }}</li>
      @endforeach
    </ul>
  @endif

  <p style="margin:0;font-size:14px;line-height:1.7;color:#cbd5e1;">
    This notice is sent only to account owners so account creation stays visible to the operational team.
  </p>
@endsection
