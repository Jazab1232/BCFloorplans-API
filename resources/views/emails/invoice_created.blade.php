@extends('emails.layouts.master')

@section('content')
    <h1 style="font-size: 20px; font-weight: normal; margin: 0 0 16px; border-bottom: 1px solid #CCCCCC; padding-bottom: 16px;">
        New Invoice Generated
    </h1>

    <h2 style="font-size: 16px; font-weight: bold; margin: 20px 0 15px;">
        Dear {{ $recipientName }},
    </h2>

    <p style="font-size: 14px; margin-bottom: 20px;">
        An invoice has been generated for order #{{ $invoice->order_id }}.
    </p>

    <div style="background-color: #F9FAFB; padding: 20px; border-radius: 8px; border: 1px solid #E5E7EB; margin-bottom: 25px;">
        <table width="100%" style="font-size: 14px; border-collapse: collapse;">
            <tr>
                <td style="padding: 6px 0; color: #4B5563; font-weight: bold;" width="35%">Invoice ID:</td>
                <td style="padding: 6px 0; color: #111827;">#{{ $invoice->id }}</td>
            </tr>
            <tr>
                <td style="padding: 6px 0; color: #4B5563; font-weight: bold;">Order ID:</td>
                <td style="padding: 6px 0; color: #111827;">#{{ $invoice->order_id }}</td>
            </tr>
            <tr>
                <td style="padding: 6px 0; color: #4B5563; font-weight: bold;">Amount Due:</td>
                <td style="padding: 6px 0; color: #111827; font-weight: bold;">${{ number_format($invoice->amount, 2) }}</td>
            </tr>
            @if($invoice->order)
            <tr>
                <td style="padding: 6px 0; color: #4B5563; font-weight: bold;">Property Address:</td>
                <td style="padding: 6px 0; color: #111827;">{{ $invoice->order->property_address ?? 'N/A' }}</td>
            </tr>
            @endif
        </table>
    </div>

    @if($recipientRole === 'agent')
    <p style="font-size: 14px; margin-bottom: 25px;">
        You can view and pay this invoice in your agent portal dashboard under Billing.
    </p>
    @endif
@endsection
