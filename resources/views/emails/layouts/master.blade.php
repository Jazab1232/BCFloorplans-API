@php
    $org = $organization ?? null;
    $orgName = $org && $org->is_whitelabel ? $org->name : 'Tojuco';
    $orgEmail = $org && $org->is_whitelabel ? $org->contact_email : 'support@tojuco.com';
    $orgPhone = $org && $org->is_whitelabel ? $org->contact_phone : '604-788-7783';
    $orgDomain = $org && $org->is_whitelabel ? ($org->domain ?? 'tojuco.com') : 'tojuco.com';
    $isWhitelabel = $org && $org->is_whitelabel;
    
    $primaryColor = ($org && $org->primary_color) ? $org->primary_color : '#2563EB';
    
    $logoUrl = null;
    if ($org && !empty($org->company_logos_urls)) {
        $logos = collect($org->company_logos_urls);
        $primary = $logos->firstWhere('type', 'primary_logo') ?? $logos->first();
        $logoUrl = $primary['url'] ?? null;
    }
    if (!$logoUrl && !$isWhitelabel) {
        $logoUrl = config('app.admin_app_img');
    }
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>@yield('title', $subject ?? 'Notification')</title>
</head>
<body style="margin: 0; padding: 0; background-color: #F5F5F5; font-family: Arial, sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="padding: 40px 0; background-color: #F5F5F5;">
    <tr>
        <td align="center">
            <table width="600" cellpadding="0" cellspacing="0" style="background-color: #ffffff; border-radius: 10px; overflow: hidden; box-shadow: 0 4px 6px rgba(0,0,0,0.05);">
                
                <!-- Header Branding -->
                <tr>
                    <td align="center" style="padding: 30px 20px; background-color: #ffffff; border-bottom: 3px solid {{ $primaryColor }};">
                        @if($logoUrl)
                            <img src="{{ $logoUrl }}" alt="{{ $orgName }} Logo" style="max-width: 180px; max-height: 70px; border: none; display: block;">
                        @else
                            <h2 style="margin: 0; color: #333333; font-size: 24px; font-weight: bold;">{{ $orgName }}</h2>
                        @endif
                    </td>
                </tr>

                <!-- Content Body -->
                <tr>
                    <td style="padding: 40px 30px; font-family: Arial, sans-serif; color: #3d4852; line-height: 1.6;">
                        @yield('content')
                        
                        <p style="font-size: 14px; color: #666; margin-top: 30px;">
                            If you have any questions or need assistance, please contact us at <a href="mailto:{{ $orgEmail }}" style="color: {{ $primaryColor }}; text-decoration: none;">{{ $orgEmail }}</a> or call {{ $orgPhone }}.
                        </p>
                        <p style="font-size: 14px; margin-top: 20px;">
                            Best regards,<br>
                            <strong>{{ $orgName }} Team</strong>
                        </p>
                    </td>
                </tr>

                <!-- Footer -->
                <tr>
                    <td style="padding: 25px 20px; text-align: center; background-color: #F9FAFB; border-top: 1px solid #E5E7EB; font-family: Arial, sans-serif;">
                        <p style="font-size: 12px; color: #9CA3AF; margin: 0 0 8px;">
                            &copy; {{ date('Y') }} {{ $orgName }}. All rights reserved.
                        </p>
                        @if(!$isWhitelabel)
                            <p style="font-size: 11px; color: #D1D5DB; margin: 0;">
                                Powered by <a href="https://tojuco.com" style="color: #9CA3AF; text-decoration: underline;">Tojuco</a>
                            </p>
                        @endif
                    </td>
                </tr>

            </table>
        </td>
    </tr>
</table>
</body>
</html>
