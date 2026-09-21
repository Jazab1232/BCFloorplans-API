@extends('emails.layouts.master')

@section('content')
    <h1 style="font-size: 20px; font-weight: normal; margin: 0 0 16px; border-bottom: 1px solid #CCCCCC; padding-bottom: 16px; color: #DC2626;">
        Order Cancelled
    </h1>

    <h2 style="font-size: 16px; font-weight: bold; margin: 20px 0 15px;">
        Dear {{ $recipientName }},
    </h2>

    <p style="font-size: 14px; margin-bottom: 20px;">
        Please note that order #{{ $order->id }} for {{ $order->property_address ?? 'N/A' }} has been cancelled.
    </p>

    <!-- Details -->
    <div style="background-color: #FEF2F2; padding: 20px; border-radius: 8px; border: 1px solid #FEE2E2; margin-bottom: 20px;">
        <table width="100%" style="font-size: 14px; border-collapse: collapse;">
            <tr>
                <td style="padding: 6px 0; color: #991B1B; font-weight: bold;" width="35%">Order ID:</td>
                <td style="padding: 6px 0; color: #7F1D1D;">#{{ $order->id }}</td>
            </tr>
            <tr>
                <td style="padding: 6px 0; color: #991B1B; font-weight: bold;">Property Address:</td>
                <td style="padding: 6px 0; color: #7F1D1D;">{{ $order->property_address ?? 'N/A' }}</td>
            </tr>
            @if($cancellationReason)
            <tr>
                <td style="padding: 6px 0; color: #991B1B; font-weight: bold;">Reason:</td>
                <td style="padding: 6px 0; color: #7F1D1D;">{{ $cancellationReason }}</td>
            </tr>
            @endif
            @if($recipientRole !== 'vendor' && $cancellationFee !== null)
            <tr>
                <td style="padding: 6px 0; color: #991B1B; font-weight: bold;">Cancellation Fee:</td>
                <td style="padding: 6px 0; color: #7F1D1D; font-weight: bold;">${{ number_format($cancellationFee, 2) }}</td>
            </tr>
            @endif
        </table>
    </div>
@endsection
