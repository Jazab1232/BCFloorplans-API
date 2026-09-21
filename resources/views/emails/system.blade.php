<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>{{ $subject ?? config('app.name') }}</title>
</head>
<body style="font-family: Arial, sans-serif; background:#f5f5f5; padding:20px;">

    <div style="max-width:600px; margin:auto; background:#ffffff; padding:20px;">
        
        {{-- <h2>Hello {{ $notifiable->name ?? 'User' }},</h2> --}}

        {{-- FRONTEND HTML GOES HERE --}}
        {!! $html !!}

        <br>

        <p>Regards,<br>{{ config('app.name') }}</p>

        <hr>

        <p style="font-size:12px; color:#888;">
            This is an automated email. Please do not reply.
        </p>

    </div>

</body>
</html>
