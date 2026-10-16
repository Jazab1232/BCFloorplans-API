@extends('emails.layouts.master')

@section('title', 'Welcome to ' . ($organization?->name ?: 'Tojuco'))

@section('content')
@php
    $org = $organization ?? null;
    $orgName = $org?->name ?: 'Tojuco Solutions';
    $primaryColor = ($org && $org->primary_color) ? $org->primary_color : '#2563EB';
    $agentName = trim(($agent->first_name ?? '') . ' ' . ($agent->last_name ?? ''));
@endphp

<h1 style="font-size: 22px; font-weight: 600; margin: 0 0 16px; color: #1a1a2e;">
    Welcome to {{ $orgName }}!
</h1>

<p style="font-size: 15px; color: #4B5563; margin: 0 0 14px;">
    Hello <strong>{{ $agentName ?: 'Agent' }}</strong>,
</p>

<p style="font-size: 15px; color: #4B5563; line-height: 1.7; margin: 0 0 20px;">
    Your agent account has been created successfully under <strong>{{ $orgName }}</strong>. You can now log in to book appointments, manage media orders, access your floor plans and virtual tours, and view your active listings.
</p>

<!-- Account Details Box -->
<table width="100%" cellpadding="0" cellspacing="0" style="background-color: #F9FAFB; border: 1px solid #E5E7EB; border-radius: 8px; margin: 0 0 24px; padding: 18px 20px;">
    <tr>
        <td>
            <p style="font-size: 12px; font-weight: 700; color: #6B7280; text-transform: uppercase; letter-spacing: 0.5px; margin: 0 0 12px;">
                Account Information
            </p>
            <p style="font-size: 14px; color: #374151; margin: 0 0 6px;">
                <strong>Name:</strong> {{ $agentName }}
            </p>
            <p style="font-size: 14px; color: #374151; margin: 0 0 6px;">
                <strong>Email:</strong> {{ $agent->email }}
            </p>
            <p style="font-size: 14px; color: #374151; margin: 0;">
                <strong>Organization:</strong> {{ $orgName }}
            </p>
        </td>
    </tr>
</table>

<!-- Login Button -->
<table width="100%" cellpadding="0" cellspacing="0" style="margin: 0 0 24px;">
    <tr>
        <td align="center">
            <a href="{{ $loginUrl }}"
               style="display: inline-block; background-color: {{ $primaryColor }}; color: #ffffff;
                      padding: 14px 36px; font-size: 15px; font-weight: 600; text-decoration: none;
                      border-radius: 6px; font-family: Arial, sans-serif; letter-spacing: 0.3px;"
               target="_blank" rel="noopener noreferrer">
                Log In to Your Portal
            </a>
        </td>
    </tr>
</table>

<p style="font-size: 13px; color: #6B7280; margin: 0 0 6px;">
    Or copy and paste this login URL into your browser:
</p>
<p style="font-size: 13px; word-break: break-all; margin: 0 0 24px;">
    <a href="{{ $loginUrl }}" style="color: {{ $primaryColor }}; text-decoration: underline;">{{ $loginUrl }}</a>
</p>

<hr style="border: none; border-top: 1px solid #E5E7EB; margin: 20px 0;">

<p style="font-size: 13px; color: #9CA3AF; margin: 0;">
    Keep your credentials secure. If you did not create this account, please contact our support team immediately.
</p>
@endsection
