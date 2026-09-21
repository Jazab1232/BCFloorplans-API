@extends('emails.layouts.master')

@section('content')
    <h1 style="font-size: 20px; font-weight: normal; margin: 0 0 16px; border-bottom: 1px solid #CCCCCC; padding-bottom: 16px; color: #DC2626;">
        3D Tour / Matterport Hosting Expired
    </h1>

    <h2 style="font-size: 16px; font-weight: bold; margin: 20px 0 15px;">
        Dear {{ $recipientName }},
    </h2>

    <p style="font-size: 14px; margin-bottom: 20px; color: #374151;">
        The Matterport 3D Tour hosting period for the following property has expired today:
    </p>

    <div style="background-color: #FEF2F2; padding: 20px; border-radius: 8px; border: 1px solid #FCA5A5; margin-bottom: 25px;">
        <table width="100%" style="font-size: 14px; border-collapse: collapse;">
            <tr>
                <td style="padding: 6px 0; color: #991B1B; font-weight: bold;" width="35%">Property Address:</td>
                <td style="padding: 6px 0; color: #111827; font-weight: 600;">{{ $propertyAddress ?? 'N/A' }}</td>
            </tr>
            <tr>
                <td style="padding: 6px 0; color: #991B1B; font-weight: bold;">Expired On:</td>
                <td style="padding: 6px 0; color: #DC2626; font-weight: bold;">{{ $expiryDate ?? 'Today' }}</td>
            </tr>
            @if(!empty($agentName))
            <tr>
                <td style="padding: 6px 0; color: #991B1B; font-weight: bold;">Agent:</td>
                <td style="padding: 6px 0; color: #111827;">{{ $agentName }}</td>
            </tr>
            @endif
        </table>
    </div>

    <p style="font-size: 14px; margin-bottom: 25px; color: #4B5563;">
        Please note that the 3D tour tab has been automatically hidden from the public tour page. You can renew hosting at any time to reactivate it immediately.
    </p>

    @if(!empty($renewalUrl))
    <div style="text-align: center; margin: 30px 0;">
        <a href="{{ $renewalUrl }}" style="background-color: #DC2626; color: #FFFFFF; padding: 12px 24px; text-decoration: none; border-radius: 6px; font-weight: bold; font-size: 14px; display: inline-block;">
            Reactivate & Renew Hosting
        </a>
    </div>
    @endif
@endsection
