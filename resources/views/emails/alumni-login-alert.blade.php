{{-- resources/views/emails/alumni-login-alert.blade.php --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Security Alert - PhilCST Alumni</title>
</head>
<body style="margin:0;padding:0;background-color:#f3f0f7;font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif;">

<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f3f0f7;padding:30px 16px;">
    <tr>
        <td align="center">
            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:580px;background-color:#ffffff;border-radius:14px;overflow:hidden;box-shadow:0 8px 32px rgba(122,63,145,0.13);">

                {{-- HEADER --}}
                <tr>
                    <td style="background:linear-gradient(135deg,#7a3f91 0%,#5a2d6f 100%);padding:40px 32px 36px;text-align:center;">
                        <h1 style="margin:0 0 6px;font-size:22px;font-weight:700;color:#ffffff;letter-spacing:-0.3px;">Security Alert</h1>
                        <p style="margin:0;font-size:13px;color:rgba(255,255,255,0.82);font-weight:400;">Philippine College of Science and Technology</p>
                    </td>
                </tr>

                {{-- BODY --}}
                <tr>
                    <td style="padding:36px 32px 0;">
                        <p style="margin:0 0 8px;font-size:15px;color:#333;line-height:1.6;">
                            Hello <strong style="color:#7a3f91;">{{ $fullName }}</strong>,
                        </p>
                        <p style="margin:0 0 24px;font-size:13px;color:#666;line-height:1.75;">
                            Someone entered the wrong password <strong style="color:#b91c1c;">{{ $attempts }} times</strong>
                            while trying to open your PhilCST Alumni account. For your protection, sign-in to your account
                            has been temporarily locked for <strong>{{ $lockMinutes }} minutes</strong>.
                        </p>

                        {{-- ATTEMPT DETAILS --}}
                        <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#fef2f2;border:1.5px solid #fecaca;border-radius:10px;margin-bottom:16px;">
                            <tr>
                                <td style="padding:16px 18px;">
                                    <p style="margin:0 0 6px;font-size:12px;font-weight:700;color:#991b1b;">Attempt Details</p>
                                    <p style="margin:0;font-size:12px;color:#7f1d1d;line-height:1.8;">
                                        @php $phTime = $attemptedAt->copy()->timezone('Asia/Manila'); @endphp
                                        Date: <strong>{{ $phTime->format('F d, Y') }}</strong><br>
                                        Time: <strong>{{ $phTime->format('h:i A') }} (PH Time)</strong><br>
                                        IP address: <strong>{{ $ipAddress }}</strong><br>
                                        Device / browser: <strong>{{ $device }}</strong>
                                    </p>
                                </td>
                            </tr>
                        </table>

                        {{-- WAS THIS YOU --}}
                        <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f5f3ff;border-left:3px solid #7a3f91;border-radius:0 8px 8px 0;margin-bottom:16px;">
                            <tr>
                                <td style="padding:14px 16px;">
                                    <p style="margin:0 0 4px;font-size:12px;font-weight:700;color:#7a3f91;">Was this you?</p>
                                    <p style="margin:0;font-size:12px;color:#555;line-height:1.65;">
                                        If yes, wait for the lock to end and sign in again with your correct password. You can also use
                                        <strong>Forgot your password?</strong> on the login page if you can't remember it.
                                    </p>
                                </td>
                            </tr>
                        </table>

                        {{-- NOT YOU --}}
                        <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#fffbeb;border-left:3px solid #f59e0b;border-radius:0 8px 8px 0;margin-bottom:24px;">
                            <tr>
                                <td style="padding:14px 16px;">
                                    <p style="margin:0 0 4px;font-size:12px;font-weight:700;color:#b45309;">If this was NOT you</p>
                                    <p style="margin:0;font-size:12px;color:#78350f;line-height:1.65;">
                                        Someone may be trying to get into your account. Go to the login page, choose
                                        <strong>Forgot your password?</strong>, and set a new password right away. Use one that you
                                        don't use anywhere else, and never share your password or verification codes with anyone.
                                    </p>
                                </td>
                            </tr>
                        </table>

                        <p style="margin:0 0 36px;font-size:13px;color:#666;line-height:1.7;">
                            Your current password was <strong>not</strong> changed. If you have questions, please contact the
                            system administrator.
                        </p>
                    </td>
                </tr>

                {{-- FOOTER --}}
                <tr>
                    <td style="background-color:#f9f5ff;border-top:1px solid #ede9fe;padding:24px 32px;text-align:center;">
                        <p style="margin:0 0 4px;font-size:12px;color:#7c3aed;font-weight:600;">PhilCST Alumni Connect</p>
                        <p style="margin:0 0 4px;font-size:11px;color:#a78bda;">Philippine College of Science and Technology</p>
                        <p style="margin:14px 0 0;font-size:10.5px;color:#c4b5fd;">
                            © {{ date('Y') }} PhilCST Alumni Connect. All rights reserved.<br>
                            This is an automated message. Please do not reply.
                        </p>
                    </td>
                </tr>

            </table>
        </td>
    </tr>
</table>

</body>
</html>