@extends('emails.layouts.master')

@section('content')
    <h1 style="font-size: 20px; font-weight: normal; margin: 0 0 16px; border-bottom: 1px solid #CCCCCC; padding-bottom: 16px; color: #DC2626;">
        Appointment Cancelled
    </h1>

    <h2 style="font-size: 16px; font-weight: bold; margin: 20px 0 15px;">
        Dear {{ $recipientName }},
    </h2>

    <p style="font-size: 14px; margin-bottom: 20px;">
        Please note that the following appointment scheduled for order #{{ $slot->order->id }} has been cancelled.
    </p>

    <div style="background-color: #FEF2F2; padding: 20px; border-radius: 8px; border: 1px solid #FEE2E2; margin-bottom: 25px;">
        <table width="100%" style="font-size: 14px; border-collapse: collapse;">
            <tr>
                <td style="padding: 6px 0; color: #991B1B; font-weight: bold;" width="35%">Service:</td>
                <td style="padding: 6px 0; color: #7F1D1D;">{{ $slot->service ? $slot->service->name : 'Service' }}</td>
            </tr>
            <tr>
                <td style="padding: 6px 0; color: #991B1B; font-weight: bold;">Original Date:</td>
                <td style="padding: 6px 0; color: #7F1D1D;">{{ \Carbon\Carbon::parse($slot->date)->format('F j, Y') }}</td>
            </tr>
            <tr>
                <td style="padding: 6px 0; color: #991B1B; font-weight: bold;">Original Time:</td>
                <td style="padding: 6px 0; color: #7F1D1D;">{{ $slot->start_time }} - {{ $slot->end_time }}</td>
            </tr>
            <tr>
                <td style="padding: 6px 0; color: #991B1B; font-weight: bold;">Property Address:</td>
                <td style="padding: 6px 0; color: #7F1D1D;">{{ $slot->order->property_address ?? 'N/A' }}</td>
            </tr>
        </table>
    </div>
@endsection
