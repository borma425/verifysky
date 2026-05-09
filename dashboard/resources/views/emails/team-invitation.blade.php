@php($headline = 'Join a VerifySky workspace')
@php($subject = 'You have been invited to VerifySky')
@extends('emails.layouts.branded')

@section('content')
  <p style="margin:0 0 16px;font-size:15px;line-height:1.7;">
    Hi,
  </p>

  <p style="margin:0 0 16px;font-size:15px;line-height:1.7;">
    {{ $invitedBy?->name ?? 'A workspace owner' }} invited you to join <strong>{{ $tenant->name }}</strong> on VerifySky as <strong>{{ $role }}</strong>.
  </p>

  <div style="margin:0 0 20px;padding:16px;border:1px solid rgba(255,255,255,0.08);border-radius:14px;background:#111827;">
    <p style="margin:0 0 8px;font-size:14px;color:#cbd5e1;"><strong>Workspace:</strong> {{ $tenant->name }}</p>
    <p style="margin:0;font-size:14px;color:#cbd5e1;"><strong>Invited email:</strong> {{ $email }}</p>
  </div>

  <p style="margin:0 0 20px;">
    <a href="{{ $acceptUrl }}" style="display:inline-block;border-radius:10px;background:#fcb900;color:#0f1723;font-size:14px;font-weight:700;text-decoration:none;padding:12px 18px;">
      Accept invitation
    </a>
  </p>

  <p style="margin:0;font-size:13px;line-height:1.7;color:#94a3b8;">
    This invitation expires in 7 days. VerifySky will never ask for your password by email.
  </p>
@endsection
