@php($headline = 'Usage warning at 80%')
@php($subject = sprintf('Usage warning for %s', $tenant->name))
@extends('emails.layouts.branded')

@section('content')
  <p style="margin:0 0 16px;font-size:15px;line-height:1.7;">
    {{ $tenant->name }} is approaching the current billing-cycle limit. This warning is sent once per cycle after usage reaches 80% of either tracked quota.
  </p>

  <div style="margin:0 0 18px;padding:16px;border-radius:16px;background:#0f1723;border:1px solid rgba(255,255,255,0.08);">
    <div style="margin-bottom:8px;"><strong>Protected sessions:</strong> {{ $billingStatus['protected_sessions']['formatted_used'] ?? '0' }} / {{ $billingStatus['protected_sessions']['formatted_limit'] ?? '0' }} ({{ $billingStatus['protected_sessions']['percentage'] ?? 0 }}%)</div>
    <div style="margin-bottom:8px;"><strong>Bot requests:</strong> {{ $billingStatus['bot_requests']['formatted_used'] ?? '0' }} / {{ $billingStatus['bot_requests']['formatted_limit'] ?? '0' }} ({{ $billingStatus['bot_requests']['percentage'] ?? 0 }}%)</div>
    <div><strong>Cycle window:</strong> {{ $usage->cycle_start_at?->utc()->format('Y-m-d') }} to {{ $usage->cycle_end_at?->utc()->format('Y-m-d') }} UTC</div>
  </div>

  <p style="margin:0;font-size:14px;line-height:1.7;color:#cbd5e1;">
    Review plan capacity before protection falls back to pass-through mode.
  </p>
@endsection
