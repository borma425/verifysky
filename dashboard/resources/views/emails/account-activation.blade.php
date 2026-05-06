@php($headline = 'Activate your VerifySky account')
@php($subject = 'Activate your VerifySky account')
@extends('emails.layouts.branded')

@section('content')
  <p style="margin:0 0 16px;font-size:15px;line-height:1.7;">
    Hi <strong>{{ $user->name }}</strong>,
  </p>

  <p style="margin:0 0 16px;font-size:15px;line-height:1.7;">
    Your VerifySky workspace is ready. Activate your account from the button below, then sign in with the password you chose during registration.
  </p>

  <div style="margin:0 0 20px;padding:16px;border:1px solid rgba(255,255,255,0.08);border-radius:14px;background:#111827;">
    <p style="margin:0 0 8px;font-size:14px;color:#cbd5e1;"><strong>Login email:</strong> {{ $user->email }}</p>
    <p style="margin:0;font-size:14px;color:#cbd5e1;"><strong>Login path:</strong> <a href="{{ $loginUrl }}" style="color:#fcb900;">{{ $loginPath }}</a></p>
  </div>

  <p style="margin:0 0 20px;">
    <a href="{{ $activationUrl }}" style="display:inline-block;border-radius:10px;background:#fcb900;color:#0f1723;font-size:14px;font-weight:700;text-decoration:none;padding:12px 18px;">
      Activate account
    </a>
  </p>

  <p style="margin:0;font-size:13px;line-height:1.7;color:#94a3b8;">
    This activation link expires in 7 days. For security, VerifySky does not send your password by email.
  </p>
@endsection
