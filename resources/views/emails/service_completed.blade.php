@extends('emails.layouts.master')

@section('title', 'Service Completed: ' . $serviceName)

@section('content')
    <h1 style="font-size: 20px; font-weight: bold; margin: 0 0 16px; border-bottom: 1px solid #E5E7EB; padding-bottom: 16px; color: #111827;">
        Service Completed
    </h1>

    <h2 style="font-size: 16px; font-weight: bold; margin: 20px 0 12px; color: #374151;">
        Dear {{ $recipientName }},
    </h2>

    <p style="font-size: 14px; margin-bottom: 20px; color: #4B5563; line-height: 1.6;">
        Great news! The service <strong>{{ $serviceName }}</strong> for your order #{{ $order?->id ?? 'N/A' }} at <strong>{{ $propertyAddress }}</strong> has been completed.
    </p>

    <div style="background-color: #F9FAFB; padding: 20px; border-radius: 8px; border: 1px solid #E5E7EB; margin-bottom: 25px;">
        <table width="100%" style="font-size: 14px; border-collapse: collapse;">
            <tr>
                <td style="padding: 6px 0; color: #4B5563; font-weight: bold;" width="35%">Order #:</td>
                <td style="padding: 6px 0; color: #111827;">{{ $order?->id ?? 'N/A' }}</td>
            </tr>
            <tr>
                <td style="padding: 6px 0; color: #4B5563; font-weight: bold;">Service:</td>
                <td style="padding: 6px 0; color: #111827;"><strong>{{ $serviceName }}</strong></td>
            </tr>
            <tr>
                <td style="padding: 6px 0; color: #4B5563; font-weight: bold;">Property Address:</td>
                <td style="padding: 6px 0; color: #111827;">{{ $propertyAddress }}</td>
            </tr>
            <tr>
                <td style="padding: 6px 0; color: #4B5563; font-weight: bold;">Status:</td>
                <td style="padding: 6px 0; color: #16A34A; font-weight: bold;">Completed</td>
            </tr>
        </table>
    </div>

    @if(!empty($serviceUrl))
        <div style="text-align: center; margin: 30px 0;">
            <a href="{{ $serviceUrl }}" style="display: inline-block; background-color: {{ $primaryColor ?? '#4290E9' }}; color: #ffffff; text-decoration: none; padding: 12px 28px; border-radius: 6px; font-weight: bold; font-size: 15px;">
                View Service in File Manager
            </a>
        </div>
        <p style="font-size: 12px; color: #9CA3AF; text-align: center; margin-top: 10px;">
            Or copy and paste this URL into your browser: <br>
            <a href="{{ $serviceUrl }}" style="color: {{ $primaryColor ?? '#4290E9' }}; word-break: break-all;">{{ $serviceUrl }}</a>
        </p>
    @endif
@endsection
