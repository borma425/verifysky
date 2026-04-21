@php($headline = 'Manual grant activated')
@php($subject = sprintf('Manual %s grant activated for %s', strtoupper((string) $grant->granted_plan_key), $tenant->name))
@extends('emails.layouts.branded')

@section('content')
  <p style="margin:0 0 16px;font-size:15px;line-height:1.7;">
    A manual <strong>{{ strtoupper((string) $grant->granted_plan_key) }}</strong> grant is now active for <strong>{{ $tenant->name }}</strong>.
  </p>

  <div style="margin:0 0 18px;padding:16px;border-radius:16px;background:#0f1723;border:1px solid rgba(255,255,255,0.08);">
    <div style="margin-bottom:8px;"><strong>Grant starts:</strong> {{ $grant->starts_at?->utc()->format('Y-m-d H:i') }} UTC</div>
    <div style="margin-bottom:8px;"><strong>Grant ends:</strong> {{ $grant->ends_at?->utc()->format('Y-m-d H:i') }} UTC</div>
    <div><strong>Reason:</strong> {{ $grant->reason ?: 'No reason provided.' }}</div>
  </div>

  <p style="margin:0;font-size:14px;line-height:1.7;color:#cbd5e1;">
    VerifySky will keep plan and billing-cycle changes visible to tenant owners through queued billing notifications.
  </p>
@endsection
