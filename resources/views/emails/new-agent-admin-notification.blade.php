@extends('emails.layouts.master')

@section('title', 'New Agent Registration – ' . ($organization?->name ?: 'Tojuco'))

@section('content')
@php
    $org = $organization ?? null;
    $orgName = $org?->name ?: 'Tojuco Solutions';
    $primaryColor = ($org && $org->primary_color) ? $org->primary_color : '#2563EB';
    $agentName = trim(($agent->first_name ?? '') . ' ' . ($agent->last_name ?? ''));
@endphp

<h1 style="font-size: 22px; font-weight: 600; margin: 0 0 16px; color: #1a1a2e;">
    New Agent Registration
</h1>

<p style="font-size: 15px; color: #4B5563; margin: 0 0 14px;">
    Hello Admin,
</p>

<p style="font-size: 15px; color: #4B5563; line-height: 1.7; margin: 0 0 20px;">
    A new agent has just signed up under <strong>{{ $orgName }}</strong>. Here are their registration details:
</p>

<!-- Agent Profile Box -->
<table width="100%" cellpadding="0" cellspacing="0" style="background-color: #F9FAFB; border: 1px solid #E5E7EB; border-radius: 8px; margin: 0 0 24px; padding: 18px 20px;">
    <tr>
        <td>
            <p style="font-size: 12px; font-weight: 700; color: #6B7280; text-transform: uppercase; letter-spacing: 0.5px; margin: 0 0 12px;">
                Agent Details
            </p>
            <p style="font-size: 14px; color: #374151; margin: 0 0 6px;">
                <strong>Name:</strong> {{ $agentName }}
            </p>
            <p style="font-size: 14px; color: #374151; margin: 0 0 6px;">
                <strong>Email:</strong> {{ $agent->email }}
            </p>
            <p style="font-size: 14px; color: #374151; margin: 0;">
                <strong>Registered At:</strong> {{ now()->toFormattedDateString() }} {{ now()->format('H:i T') }}
            </p>
        </td>
    </tr>
</table>

<p style="font-size: 14px; color: #6B7280; margin: 0 0 20px;">
    You can review and manage this agent's profile and permissions in your admin dashboard.
</p>

<table width="100%" cellpadding="0" cellspacing="0" style="margin: 0 0 20px;">
    <tr>
        <td align="center">
            <a href="{{ $loginUrl }}"
               style="display: inline-block; background-color: {{ $primaryColor }}; color: #ffffff;
                      padding: 12px 30px; font-size: 14px; font-weight: 600; text-decoration: none;
                      border-radius: 6px; font-family: Arial, sans-serif; letter-spacing: 0.3px;"
               target="_blank" rel="noopener noreferrer">
                Go to Portal
            </a>
        </td>
    </tr>
</table>
@endsection
