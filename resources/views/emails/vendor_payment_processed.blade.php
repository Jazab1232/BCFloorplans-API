@extends('emails.layouts.master')

@section('content')
    <h1 style="font-size: 20px; font-weight: normal; margin: 0 0 16px; border-bottom: 1px solid #CCCCCC; padding-bottom: 16px; color: #16A34A;">
        ✓ Payment Transferred Successfully
    </h1>

    <h2 style="font-size: 16px; font-weight: bold; margin: 20px 0 15px;">
        Dear {{ $recipientName }},
    </h2>

    <p style="font-size: 14px; margin-bottom: 20px;">
        A payment has been successfully transferred to your account for the completed service(s).
    </p>

    <!-- Payment Details -->
    <div style="background-color: #DCFCE7; padding: 20px; border-radius: 8px; border: 1px solid #BBF7D0; margin-bottom: 20px; color: #14532D;">
        <h3 style="font-size: 15px; margin: 0 0 12px; color: #14532D; border-bottom: 1px solid #BBF7D0; padding-bottom: 8px;">Payment Information</h3>
        <table width="100%" style="font-size: 14px; border-collapse: collapse;">
            <tr>
                <td style="padding: 6px 0; color: #166534; font-weight: bold;" width="35%">Amount Transferred:</td>
                <td style="padding: 6px 0; color: #14532D; font-weight: bold;">
                    ${{ number_format($payment->amount, 2) }} {{ strtoupper($payment->currency ?? 'USD') }}
                </td>
            </tr>
            <tr>
                <td style="padding: 6px 0; color: #166534; font-weight: bold;">Payment ID:</td>
                <td style="padding: 6px 0; color: #14532D;">{{ $payment->id }}</td>
            </tr>
            <tr>
                <td style="padding: 6px 0; color: #166534; font-weight: bold;">Transfer Date:</td>
                <td style="padding: 6px 0; color: #14532D;">
                    {{ \Carbon\Carbon::parse($payment->created_at)->format('F j, Y g:i A') }}
                </td>
            </tr>
            <tr>
                <td style="padding: 6px 0; color: #166534; font-weight: bold;">Transfer ID:</td>
                <td style="padding: 6px 0; color: #14532D;">{{ $payment->stripe_transfer_id ?? 'N/A' }}</td>
            </tr>
        </table>
    </div>

    @if(!empty($services) && $services->count() > 0)
    <!-- Services Paid -->
    <div style="background-color: #F9FAFB; padding: 20px; border-radius: 8px; border: 1px solid #E5E7EB; margin-bottom: 20px;">
        <h3 style="font-size: 15px; margin: 0 0 12px; color: #111827; border-bottom: 1px solid #E5E7EB; padding-bottom: 8px;">Completed Services Paid</h3>
        <table width="100%" style="font-size: 14px; border-collapse: collapse;">
            @foreach($services as $service)
            <tr>
                <td style="padding: 8px 0; border-bottom: 1px solid #E5E7EB; color: #111827;">
                    {{ $service->service->name ?? 'Service' }}
                    @if($service->order)
                        <br><small style="color: #6B7280;">Order #{{ $service->order->id }} - {{ $service->order->property_address }}</small>
                    @endif
                </td>
                <td style="padding: 8px 0; border-bottom: 1px solid #E5E7EB; text-align: right; color: #111827; font-weight: bold;">
                    ${{ number_format($service->vendor_amount ?? 0, 2) }}
                </td>
            </tr>
            @endforeach
        </table>
    </div>
    @endif
@endsection
