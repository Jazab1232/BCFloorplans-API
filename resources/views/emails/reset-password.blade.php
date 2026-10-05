@extends('emails.layouts.master')

@section('title', 'Reset Your Password')

@section('content')
@php
    $org     = $organization ?? null;
    $orgName = $org?->name ?: 'Tojuco';
    $primaryColor = ($org && $org->primary_color) ? $org->primary_color : '#2563EB';
@endphp

<h1 style="font-size: 22px; font-weight: 600; margin: 0 0 16px; color: #1a1a2e;">
    Reset Your Password
</h1>

<p style="font-size: 15px; color: #4B5563; margin: 0 0 12px;">
    Hello {{ $name ?? 'there' }},
</p>

<p style="font-size: 15px; color: #4B5563; line-height: 1.7; margin: 0 0 24px;">
    We received a request to reset the password for your <strong>{{ $orgName }}</strong> account.
    Click the button below to choose a new password. This link is valid for <strong>60 minutes</strong>.
</p>

<table width="100%" cellpadding="0" cellspacing="0" style="margin: 0 0 24px;">
    <tr>
        <td align="center">
            <a href="{{ $url }}"
               style="display: inline-block; background-color: {{ $primaryColor }}; color: #ffffff;
                      padding: 14px 36px; font-size: 15px; font-weight: 600; text-decoration: none;
                      border-radius: 6px; font-family: Arial, sans-serif; letter-spacing: 0.3px;"
               target="_blank" rel="noopener noreferrer">
                Reset Password
            </a>
        </td>
    </tr>
</table>

<p style="font-size: 13px; color: #6B7280; margin: 0 0 6px;">
    Or copy and paste this link into your browser:
</p>
<p style="font-size: 13px; word-break: break-all; margin: 0 0 24px;">
    <a href="{{ $url }}" style="color: {{ $primaryColor }}; text-decoration: underline;">{{ $url }}</a>
</p>

<hr style="border: none; border-top: 1px solid #E5E7EB; margin: 20px 0;">

<p style="font-size: 13px; color: #9CA3AF; margin: 0;">
    If you did not request a password reset, you can safely ignore this email.
    Your password will remain unchanged.
</p>
@endsection