@extends('emails.layouts.master')

@section('content')
    <h1 style="font-size: 20px; font-weight: normal; margin: 0 0 16px; border-bottom: 1px solid #CCCCCC; padding-bottom: 16px; color: #2563EB;">
        🖨️ Feature Sheet Print Request Ready
    </h1>

    <h2 style="font-size: 16px; font-weight: bold; margin: 20px 0 15px;">
        Hello {{ $recipientName }},
    </h2>

    <p style="font-size: 14px; margin-bottom: 20px;">
        A feature sheet has been <strong>published</strong> and its print invoice is <strong>paid</strong>. It is now ready for physical printing and fulfillment.
    </p>

    <!-- Print Request Details -->
    <div style="background-color: #EFF6FF; padding: 20px; border-radius: 8px; border: 1px solid #BFDBFE; margin-bottom: 20px; color: #1E3A8A;">
        <h3 style="font-size: 15px; margin: 0 0 12px; color: #1E40AF; border-bottom: 1px solid #BFDBFE; padding-bottom: 8px;">Print Specifications</h3>
        <table width="100%" style="font-size: 14px; border-collapse: collapse;">
            <tr>
                <td style="padding: 6px 0; color: #1E40AF; font-weight: bold;" width="35%">Copies:</td>
                <td style="padding: 6px 0; color: #1E3A8A; font-weight: bold;">
                    {{ $printRequest->copies }} Copies
                </td>
            </tr>
            <tr>
                <td style="padding: 6px 0; color: #1E40AF; font-weight: bold;">Bleed Margins:</td>
                <td style="padding: 6px 0; color: #1E3A8A;">
                    {{ $printRequest->with_bleed ? 'With Bleed (Print with margins)' : 'Standard (No Bleed)' }}
                </td>
            </tr>
            @if($printRequest->amount)
            <tr>
                <td style="padding: 6px 0; color: #1E40AF; font-weight: bold;">Amount Paid:</td>
                <td style="padding: 6px 0; color: #1E3A8A;">${{ number_format($printRequest->amount, 2) }}</td>
            </tr>
            @endif
            @if(!empty($printRequest->additional_info))
            <tr>
                <td style="padding: 6px 0; color: #1E40AF; font-weight: bold;">Special Instructions:</td>
                <td style="padding: 6px 0; color: #1E3A8A;">{{ $printRequest->additional_info }}</td>
            </tr>
            @endif
        </table>
    </div>

    @if(isset($order))
    <!-- Related Order Details -->
    <div style="background-color: #F9FAFB; padding: 20px; border-radius: 8px; border: 1px solid #E5E7EB; margin-bottom: 20px;">
        <h3 style="font-size: 15px; margin: 0 0 12px; color: #111827; border-bottom: 1px solid #E5E7EB; padding-bottom: 8px;">Property & Order Details</h3>
        <table width="100%" style="font-size: 14px; border-collapse: collapse;">
            <tr>
                <td style="padding: 6px 0; color: #4B5563; font-weight: bold;" width="35%">Order ID:</td>
                <td style="padding: 6px 0; color: #111827;">#{{ $order->id }}</td>
            </tr>
            @if($order->property_address)
            <tr>
                <td style="padding: 6px 0; color: #4B5563; font-weight: bold;">Property Address:</td>
                <td style="padding: 6px 0; color: #111827;">{{ $order->property_address }}</td>
            </tr>
            @endif
            @if($order->agent)
            <tr>
                <td style="padding: 6px 0; color: #4B5563; font-weight: bold;">Agent:</td>
                <td style="padding: 6px 0; color: #111827;">{{ $order->agent->name }} ({{ $order->agent->email }})</td>
            </tr>
            @endif
        </table>
    </div>
    @endif

    @if($featureSheet->url)
    <!-- PDF Link Button -->
    <div style="text-align: center; margin: 30px 0;">
        <a href="{{ $featureSheet->url }}" target="_blank" style="display: inline-block; background-color: #2563EB; color: #ffffff; padding: 12px 24px; text-decoration: none; border-radius: 6px; font-weight: bold; font-size: 14px;">
            📄 Download Feature Sheet PDF
        </a>
    </div>
    @endif

@endsection
