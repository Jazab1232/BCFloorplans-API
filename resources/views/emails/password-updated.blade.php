<!DOCTYPE html>
<html>
 <head>
 <meta charset="UTF-8">
 <title>Password Updated</title>
 </head>
 <body style="margin: 0; padding: 0; background-color: #F5F5F5; font-family: Arial, sans-serif;">
 <table width="100%" cellpadding="0" cellspacing="0" style="padding: 40px 0; background-color: #F5F5F5;">
  <tr>
  <td align="center">
   <table width="600" cellpadding="0" cellspacing="0" style="background-color: #ffffff; border-radius: 10px;">
   <tr>
    <td align="center" style="padding: 20px;">
    <img src="{{ config('app.admin_app_img') }}" alt="BCFP Logo" style="width: 135px; border: none;">
    </td>
   </tr>
   <tr>
    <td style="padding: 20px; font-family: Arial, sans-serif; color: #3d4852;">
    <h1 style="font-size: 24px; font-weight: normal; margin: 0 0 16px; border-bottom: 1px solid #CCCCCC; padding-bottom: 16px;">
     Password Updated for BCFP Software
    </h1>
    <h2 style="font-size: 16px; font-weight: bold; margin: 20px 0 25px;">Hello</h2>
    <p style="font-size: 14px; line-height: 30px; margin: 0 0 30px;color: #424242;">
     The administrator has updated your account password.
    </p>
    <p style="font-size: 14px; line-height: 30px; margin: 0 0 10px;color: #424242;">
     New Password: <strong>{{ $newPassword }}</strong>
     <br />Please change this password after logging in.
    </p>
    <table width="100%" cellpadding="0" cellspacing="0">
     <tr>
     <td align="center">
      {{-- <a href="{{ $url }}"
       style="display: inline-block; background-color: #4290E9;width: 330px; color: #ffffff; padding: 12px 24px; font-size: 16px; text-decoration: none; border-radius: 6px; font-family: Arial, sans-serif;"
       target="_blank" rel="noopener noreferrer">
      Reset Password Now
      </a> --}}
     </td>
     </tr>
    </table>
    {{-- <p style="font-size: 14px; line-height: 100%; color: #424242; margin: 30px 0 2px;">
     Reset Link:
    </p>
    <p style="font-size: 14px; line-height: 100%; word-break: break-word;margin-top: 0;">
     <a href="{{ $url }}" style="color: #4290E9;">{{ $url }}</a>
    </p> --}}
    <hr style="border: none; border-top: 1px solid #CCCCCC; margin: 16px 0;">
    <p style="font-size: 12px; color: #666666; line-height: 20px;margin: 0;">
     You received this email because you signed up on our website. This is an automated email. If you need to get in touch, email us at support@bcfpsoftware.com <a href="#" style="color: #4290E9;">unsubscribe</a>.
    </p>
    </td>
   </tr>
   </table>
  </td>
  </tr>
 </table>
 </body>
</html>