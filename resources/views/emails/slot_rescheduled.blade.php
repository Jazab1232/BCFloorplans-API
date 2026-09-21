@extends('emails.layouts.master')

@section('content')
    <h1 style="font-size: 20px; font-weight: normal; margin: 0 0 16px; border-bottom: 1px solid #CCCCCC; padding-bottom: 16px;">
        Appointment Rescheduled
    </h1>

    <h2 style="font-size: 16px; font-weight: bold; margin: 20px 0 15px;">
        Dear {{ $recipientName }},
    </h2>

    <p style="font-size: 14px; margin-bottom: 20px;">
        Please note that the appointment for <strong>{{ $slot->service ? $slot->service->name : 'Service' }}</strong> in order #{{ $slot->order->id }} has been rescheduled.
    </p>

    @if($oldDate || $oldStartTime)
    <div style="background-color: #FEF3C7; padding: 15px; border-radius: 8px; border: 1px solid #FDE68A; margin-bottom: 20px; font-size: 14px;">
        <strong>Previous Schedule:</strong><br>
        Date: {{ $oldDate ? \Carbon\Carbon::parse($oldDate)->format('F j, Y') : 'N/A' }}<br>
        Time: {{ $oldStartTime ?? 'N/A' }}
    </div>
    @endif

    <div style="background-color: #ECFDF5; padding: 20px; border-radius: 8px; border: 1px solid #A7F3D0; margin-bottom: 25px;">
        <h3 style="font-size: 15px; margin: 0 0 10px; color: #065F46;">New Schedule Details</h3>
        <table width="100%" style="font-size: 14px; border-collapse: collapse;">
            <tr>
                <td style="padding: 6px 0; color: #065F46; font-weight: bold;" width="35%">Service:</td>
                <td style="padding: 6px 0; color: #047857;">{{ $slot->service ? $slot->service->name : 'Service' }}</td>
            </tr>
            <tr>
                <td style="padding: 6px 0; color: #065F46; font-weight: bold;">New Date:</td>
                <td style="padding: 6px 0; color: #047857;">{{ \Carbon\Carbon::parse($slot->date)->format('F j, Y') }}</td>
            </tr>
            <tr>
                <td style="padding: 6px 0; color: #065F46; font-weight: bold;">New Time:</td>
                <td style="padding: 6px 0; color: #047857;">{{ $slot->start_time }} - {{ $slot->end_time }}</td>
            </tr>
            <tr>
                <td style="padding: 6px 0; color: #065F46; font-weight: bold;">Property Address:</td>
                <td style="padding: 6px 0; color: #047857;">{{ $slot->order->property_address ?? 'N/A' }}</td>
            </tr>
            @if($recipientRole !== 'vendor')
                @if($slot->vendor)
                    <tr>
                        <td style="padding: 6px 0; color: #065F46; font-weight: bold;">Vendor:</td>
                        <td style="padding: 6px 0; color: #047857;">{{ $slot->vendor->first_name }} {{ $slot->vendor->last_name }}</td>
                    </tr>
                @endif
            @endif
        </table>
    </div>
@endsection
