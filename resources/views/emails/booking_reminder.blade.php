@extends('emails.layouts.master')

@section('content')
    <h1 style="font-size: 20px; font-weight: normal; margin: 0 0 16px; border-bottom: 1px solid #CCCCCC; padding-bottom: 16px;">
        Upcoming Appointment Reminder
    </h1>

    <h2 style="font-size: 16px; font-weight: bold; margin: 20px 0 15px;">
        Dear Customer,
    </h2>

    <p style="font-size: 14px; margin-bottom: 20px;">
        This is a friendly reminder that you have an upcoming service appointment scheduled.
    </p>

    <!-- Details -->
    <div style="background-color: #F9FAFB; padding: 20px; border-radius: 8px; border: 1px solid #E5E7EB; margin-bottom: 25px;">
        <table width="100%" style="font-size: 14px; border-collapse: collapse;">
            <tr>
                <td style="padding: 6px 0; color: #4B5563; font-weight: bold;" width="35%">Service:</td>
                <td style="padding: 6px 0; color: #111827;">{{ $orderSlot->service ? $orderSlot->service->name : 'Service' }}</td>
            </tr>
            <tr>
                <td style="padding: 6px 0; color: #4B5563; font-weight: bold;">Date:</td>
                <td style="padding: 6px 0; color: #111827;">{{ \Carbon\Carbon::parse($orderSlot->date)->format('F j, Y') }}</td>
            </tr>
            <tr>
                <td style="padding: 6px 0; color: #4B5563; font-weight: bold;">Time:</td>
                <td style="padding: 6px 0; color: #111827;">{{ $orderSlot->start_time }} - {{ $orderSlot->end_time }}</td>
            </tr>
            <tr>
                <td style="padding: 6px 0; color: #4B5563; font-weight: bold;">Property Address:</td>
                <td style="padding: 6px 0; color: #111827;">{{ $orderSlot->order->property_address ?? 'N/A' }}</td>
            </tr>
            @if($recipientRole !== 'vendor')
                @if($orderSlot->vendor)
                <tr>
                    <td style="padding: 6px 0; color: #4B5563; font-weight: bold;">Vendor:</td>
                    <td style="padding: 6px 0; color: #111827;">{{ $orderSlot->vendor->first_name }} {{ $orderSlot->vendor->last_name }}</td>
                </tr>
                @endif
            @endif
        </table>
    </div>
@endsection
