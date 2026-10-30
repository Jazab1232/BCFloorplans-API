@extends('emails.layouts.master')

@section('content')
    <h1 style="font-size: 20px; font-weight: normal; margin: 0 0 16px; border-bottom: 1px solid #CCCCCC; padding-bottom: 16px; color: #16A34A;">
        ✅ Print Request Completed
    </h1>

    <h2 style="font-size: 16px; font-weight: bold; margin: 20px 0 15px;">
        Hello {{ $recipientName }},
    </h2>

    <p style="font-size: 14px; margin-bottom: 20px; line-height: 1.5;">
        Great news! Your feature sheet print order has been completed and is now ready.
    </p>

    <!-- Print Request Details -->
    <div style="background-color: #F0FDF4; padding: 20px; border-radius: 8px; border: 1px solid #BBF7D0; margin-bottom: 20px; color: #14532D;">
        <h3 style="font-size: 15px; margin: 0 0 12px; color: #15803D; border-bottom: 1px solid #BBF7D0; padding-bottom: 8px;">Order Details</h3>
        <table width="100%" style="font-size: 14px; border-collapse: collapse;">
            <tr>
                <td style="padding: 6px 0; color: #15803D; font-weight: bold;" width="35%">Copies:</td>
                <td style="padding: 6px 0; color: #14532D; font-weight: bold;">
                    {{ $printRequest->copies }} Copies
                </td>
            </tr>
            <tr>
                <td style="padding: 6px 0; color: #15803D; font-weight: bold;">Margins:</td>
                <td style="padding: 6px 0; color: #14532D;">
                    {{ $printRequest->with_bleed ? 'With Bleed (Print with margins)' : 'Standard (No Bleed)' }}
                </td>
            </tr>
            <tr>
                <td style="padding: 6px 0; color: #15803D; font-weight: bold;">Status:</td>
                <td style="padding: 6px 0; color: #16A34A; font-weight: bold;">Completed</td>
            </tr>
            @if(!empty($printRequest->additional_info))
            <tr>
                <td style="padding: 6px 0; color: #15803D; font-weight: bold;">Instructions:</td>
                <td style="padding: 6px 0; color: #14532D;">{{ $printRequest->additional_info }}</td>
            </tr>
            @endif
        </table>
    </div>

    @if(isset($order))
    <!-- Related Property Details -->
    <div style="background-color: #F9FAFB; padding: 20px; border-radius: 8px; border: 1px solid #E5E7EB; margin-bottom: 20px;">
        <h3 style="font-size: 15px; margin: 0 0 12px; color: #111827; border-bottom: 1px solid #E5E7EB; padding-bottom: 8px;">Property Information</h3>
        <table width="100%" style="font-size: 14px; border-collapse: collapse;">
            @if($order->id)
            <tr>
                <td style="padding: 6px 0; color: #4B5563; font-weight: bold;" width="35%">Order ID:</td>
                <td style="padding: 6px 0; color: #111827;">#{{ $order->id }}</td>
            </tr>
            @endif
            @if($order->property_address)
            <tr>
                <td style="padding: 6px 0; color: #4B5563; font-weight: bold;">Property Address:</td>
                <td style="padding: 6px 0; color: #111827;">{{ $order->property_address }}</td>
            </tr>
            @endif
        </table>
    </div>
    @endif

    @if($featureSheet && $featureSheet->url)
    <div style="text-align: center; margin: 30px 0;">
        <a href="{{ $featureSheet->url }}" target="_blank" style="display: inline-block; background-color: #16A34A; color: #ffffff; padding: 12px 24px; text-decoration: none; border-radius: 6px; font-weight: bold; font-size: 14px;">
            📄 View Printed Feature Sheet PDF
        </a>
    </div>
    @endif
@endsection
