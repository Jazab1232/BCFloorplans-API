@extends('emails.layouts.master')

@section('content')
    <h1 style="font-size: 20px; font-weight: normal; margin: 0 0 16px; border-bottom: 1px solid #CCCCCC; padding-bottom: 16px; color: #16A34A;">
        3D Tour / Matterport Hosting Renewed
    </h1>

    <h2 style="font-size: 16px; font-weight: bold; margin: 20px 0 15px;">
        Dear {{ $recipientName }},
    </h2>

    <p style="font-size: 14px; margin-bottom: 20px; color: #374151;">
        The Matterport 3D Tour hosting period has been successfully renewed and extended:
    </p>

    <div style="background-color: #F0FDF4; padding: 20px; border-radius: 8px; border: 1px solid #BBF7D0; margin-bottom: 25px;">
        <table width="100%" style="font-size: 14px; border-collapse: collapse;">
            <tr>
                <td style="padding: 6px 0; color: #166534; font-weight: bold;" width="35%">Property Address:</td>
                <td style="padding: 6px 0; color: #111827; font-weight: 600;">{{ $propertyAddress ?? 'N/A' }}</td>
            </tr>
            <tr>
                <td style="padding: 6px 0; color: #166534; font-weight: bold;">New Expiry Date:</td>
                <td style="padding: 6px 0; color: #16A34A; font-weight: bold;">{{ $newExpiryDate ?? 'N/A' }}</td>
            </tr>
            <tr>
                <td style="padding: 6px 0; color: #166534; font-weight: bold;">Duration Added:</td>
                <td style="padding: 6px 0; color: #111827;">{{ $durationMonths ?? '6' }} Months</td>
            </tr>
            @if(isset($amount))
            <tr>
                <td style="padding: 6px 0; color: #166534; font-weight: bold;">Amount Paid:</td>
                <td style="padding: 6px 0; color: #111827; font-weight: bold;">${{ number_format($amount, 2) }} CAD</td>
            </tr>
            @endif
        </table>
    </div>

    <p style="font-size: 14px; margin-bottom: 25px; color: #4B5563;">
        The 3D Tour remains active and fully accessible on your property tour pages. Thank you for your business!
    </p>
@endsection
