<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Alumni Account Verification</title>
</head>
<body style="margin:0;padding:0;background-color:#f3f0f7;font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif;">

<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f3f0f7;padding:30px 16px;">
    <tr>
        <td align="center">
            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:580px;background-color:#ffffff;border-radius:14px;overflow:hidden;box-shadow:0 8px 32px rgba(122,63,145,0.13);">

                {{-- HEADER --}}
                <tr>
                    <td style="background:linear-gradient(135deg,#7a3f91 0%,#5a2d6f 100%);padding:40px 32px 36px;text-align:center;">
                        <div style="width:56px;height:56px;background:rgba(255,255,255,0.18);border-radius:14px;display:inline-flex;align-items:center;justify-content:center;margin-bottom:16px;">
                            <span style="font-size:26px;line-height:1;">🎓</span>
                        </div>
                        <h1 style="margin:0 0 6px;font-size:22px;font-weight:700;color:#ffffff;letter-spacing:-0.3px;">Alumni Account Setup</h1>
                        <p style="margin:0;font-size:13px;color:rgba(255,255,255,0.82);font-weight:400;">Philippine College of Science and Technology</p>
                    </td>
                </tr>

                {{-- BODY --}}
                <tr>
                    <td style="padding:36px 32px 0;">
                        <p style="margin:0 0 8px;font-size:15px;color:#333;line-height:1.6;">
                            Hello <strong style="color:#7a3f91;">{{ $alumni->getFullName() }}</strong>,
                        </p>
                        <p style="margin:0 0 28px;font-size:13px;color:#666;line-height:1.75;">
                            You are setting up your alumni account and registering your email address. Use the verification code below to complete the process. This is a one-time setup — you will not need to do this again.
                        </p>

                        {{-- OTP BOX --}}
                        <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f9f5ff;border:2px dashed #c4b5fd;border-radius:12px;margin-bottom:24px;">
                            <tr>
                                <td style="padding:28px 24px;text-align:center;">
                                    <span style="display:block;font-size:10px;font-weight:700;color:#7a3f91;text-transform:uppercase;letter-spacing:1.5px;margin-bottom:16px;">Your Verification Code</span>
                                    <div style="display:inline-block;background:#ffffff;border:1.5px solid #e5e7eb;border-radius:10px;padding:16px 28px;margin-bottom:14px;">
                                        <span style="font-size:40px;font-weight:700;letter-spacing:10px;color:#1f2937;font-family:'Courier New',monospace;">{{ $otp }}</span>
                                    </div>
                                    <p style="margin:0;font-size:12px;color:#9ca3af;">
                                        ⏱&nbsp; This code expires in <strong style="color:#7a3f91;">10 minutes</strong>
                                    </p>
                                </td>
                            </tr>
                        </table>

                        {{-- ACCOUNT INFO --}}
                        <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f0fdf4;border:1.5px solid #bbf7d0;border-radius:10px;margin-bottom:16px;">
                            <tr>
                                <td style="padding:16px 18px;">
                                    <p style="margin:0 0 6px;font-size:12px;font-weight:700;color:#166534;">Your Account Information</p>
                                    <p style="margin:0;font-size:12px;color:#15803d;line-height:1.8;">
                                        Student ID: <strong>{{ $alumni->student_id }}</strong><br>
                                        Course: <strong>{{ $alumni->course_code }} — {{ $alumni->course_name }}</strong><br>
                                        Batch: <strong>{{ $alumni->batch }}</strong>
                                    </p>
                                </td>
                            </tr>
                        </table>

                        {{-- HOW TO USE --}}
                        <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f5f3ff;border-left:3px solid #7a3f91;border-radius:0 8px 8px 0;margin-bottom:16px;">
                            <tr>
                                <td style="padding:14px 16px;">
                                    <p style="margin:0 0 4px;font-size:12px;font-weight:700;color:#7a3f91;">How to use</p>
                                    <p style="margin:0;font-size:12px;color:#555;line-height:1.65;">
                                        Enter this 6-digit code in the verification step of the account setup wizard to confirm your email address and complete your account activation.
                                    </p>
                                </td>
                            </tr>
                        </table>

                        {{-- WARNING --}}
                        <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#fffbeb;border-left:3px solid #f59e0b;border-radius:0 8px 8px 0;margin-bottom:24px;">
                            <tr>
                                <td style="padding:14px 16px;">
                                    <p style="margin:0 0 4px;font-size:12px;font-weight:700;color:#b45309;">Security Notice</p>
                                    <p style="margin:0;font-size:12px;color:#78350f;line-height:1.65;">
                                        Never share this code with anyone. PhilCST staff will <strong>never</strong> ask for your OTP via email, call, or message.
                                    </p>
                                </td>
                            </tr>
                        </table>

                        <p style="margin:0 0 36px;font-size:13px;color:#666;line-height:1.7;">
                            If you did not initiate this account setup, please contact your administrator immediately at <a href="mailto:admin@philcst.edu.ph" style="color:#7a3f91;font-weight:600;">admin@philcst.edu.ph</a>.
                        </p>
                    </td>
                </tr>

                {{-- FOOTER --}}
                <tr>
                    <td style="background-color:#f9f5ff;border-top:1px solid #ede9fe;padding:24px 32px;text-align:center;">
                        <p style="margin:0 0 4px;font-size:12px;color:#7c3aed;font-weight:600;">Philcst Alumni Connect</p>
                        <p style="margin:0 0 4px;font-size:11px;color:#a78bda;">Philippine College of Science and Technology</p>
                        <p style="margin:14px 0 0;font-size:11px;color:#a78bda;">
                            For support: <a href="mailto:admin@philcst.edu.ph" style="color:#7a3f91;font-weight:600;text-decoration:none;">admin@philcst.edu.ph</a>
                        </p>
                        <p style="margin:6px 0 0;font-size:10.5px;color:#c4b5fd;">
                            © {{ date('Y') }} Philcst Alumni Connect. All rights reserved.<br>
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