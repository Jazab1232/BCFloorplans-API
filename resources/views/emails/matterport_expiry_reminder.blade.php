@extends('emails.layouts.master')

@section('content')
    <h1 style="font-size: 20px; font-weight: normal; margin: 0 0 16px; border-bottom: 1px solid #CCCCCC; padding-bottom: 16px;">
        3D Tour / Matterport Hosting Expiry Notice
    </h1>

    <h2 style="font-size: 16px; font-weight: bold; margin: 20px 0 15px;">
        Dear {{ $recipientName }},
    </h2>

    <p style="font-size: 14px; margin-bottom: 20px; color: #374151;">
        The Matterport 3D Tour hosting period for the following property is expiring soon:
    </p>

    <div style="background-color: #FFFBEB; padding: 20px; border-radius: 8px; border: 1px solid #FDE68A; margin-bottom: 25px;">
        <table width="100%" style="font-size: 14px; border-collapse: collapse;">
            <tr>
                <td style="padding: 6px 0; color: #92400E; font-weight: bold;" width="35%">Property Address:</td>
                <td style="padding: 6px 0; color: #111827; font-weight: 600;">{{ $propertyAddress ?? 'N/A' }}</td>
            </tr>
            <tr>
                <td style="padding: 6px 0; color: #92400E; font-weight: bold;">Expiry Date:</td>
                <td style="padding: 6px 0; color: #DC2626; font-weight: bold;">{{ $expiryDate ?? 'N/A' }}</td>
            </tr>
            <tr>
                <td style="padding: 6px 0; color: #92400E; font-weight: bold;">Days Remaining:</td>
                <td style="padding: 6px 0; color: #B45309; font-weight: bold;">{{ $daysRemaining ?? '0' }} Days</td>
            </tr>
            @if(!empty($agentName))
            <tr>
                <td style="padding: 6px 0; color: #92400E; font-weight: bold;">Agent:</td>
                <td style="padding: 6px 0; color: #111827;">{{ $agentName }}</td>
            </tr>
            @endif
        </table>
    </div>

    <p style="font-size: 14px; margin-bottom: 25px; color: #4B5563;">
        To keep the 3D tour active and visible on your public listing pages, please renew hosting before the expiration date.
    </p>

    @if(!empty($renewalUrl))
    <div style="text-align: center; margin: 30px 0;">
        <a href="{{ $renewalUrl }}" style="background-color: #4290E9; color: #FFFFFF; padding: 12px 24px; text-decoration: none; border-radius: 6px; font-weight: bold; font-size: 14px; display: inline-block;">
            Renew 3D Tour Hosting
        </a>
    </div>
    @endif
@endsection
