<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Password Change Verification</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f3f0f7;
            padding: 20px;
        }
        .container {
            max-width: 600px;
            margin: 0 auto;
            background-color: #ffffff;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 10px 25px rgba(122, 63, 145, 0.15);
        }
        .header {
            background: linear-gradient(135deg, #7a3f91 0%, #5a2d6f 100%);
            padding: 40px 30px;
            text-align: center;
            color: #ffffff;
        }
        .header h1 { font-size: 26px; font-weight: 700; margin-bottom: 6px; letter-spacing: -0.5px; }
        .header p { font-size: 13px; opacity: 0.88; font-weight: 400; }
        .content { padding: 40px 30px; }
        .greeting { font-size: 15px; color: #333; margin-bottom: 20px; line-height: 1.6; }
        .greeting strong { color: #7a3f91; }
        .body-text { font-size: 13px; color: #555; line-height: 1.7; margin-bottom: 20px; }
        .otp-section {
            background-color: #f9f5ff;
            border: 2px dashed #d8b4fe;
            border-radius: 10px;
            padding: 30px;
            margin: 0 0 20px;
            text-align: center;
        }
        .otp-label {
            font-size: 11px; font-weight: 700; color: #7a3f91;
            text-transform: uppercase; letter-spacing: 1px;
            display: block; margin-bottom: 15px;
        }
        .otp-code {
            font-size: 38px; font-weight: 700;
            letter-spacing: 8px; color: #1f2937;
            font-family: 'Courier New', monospace;
            background-color: #fff; padding: 15px 20px;
            border-radius: 6px; border: 1px solid #e5e7eb;
            display: inline-block; margin-bottom: 12px;
        }
        .otp-expires { font-size: 12px; color: #888; margin-top: 4px; }
        .info-text {
            font-size: 12px; color: #666; line-height: 1.6;
            padding: 14px; background-color: #f5f3ff;
            border-radius: 6px; border-left: 3px solid #7a3f91;
            margin-bottom: 16px;
        }
        .warning-box {
            font-size: 12px; color: #856404; line-height: 1.6;
            padding: 14px; background-color: #fff3cd;
            border-radius: 6px; border-left: 3px solid #ffc107;
            margin-bottom: 20px;
        }
        .footer {
            background-color: #f9f5ff; padding: 25px 30px;
            text-align: center; border-top: 1px solid #e9d5ff;
        }
        .footer-text { font-size: 12px; color: #9333ea; line-height: 1.8; }
        .footer-text a { color: #7a3f91; text-decoration: none; font-weight: 600; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🔐 Password Change Verification</h1>
            <p>Philippine College of Science and Technology</p>
        </div>

        <div class="content">
            <div class="greeting">
                Hello <strong>{{ $organizer->name }}</strong>,
            </div>

            <p class="body-text">
                You initiated a password change request for your organizer account. To complete this process, please verify your identity using the One-Time Password (OTP) below:
            </p>

            <div class="otp-section">
                <span class="otp-label">📋 Your Verification Code</span>
                <div class="otp-code">{{ $otp }}</div>
                <p class="otp-expires">⏱️ This code expires in 10 minutes</p>
            </div>

            <div class="info-text">
                <strong style="color: #7a3f91;">✓ How to use:</strong><br>
                Enter the 6-digit code above in the password change form, then complete your new password setup and log in with your new credentials.
            </div>

            <div class="warning-box">
                <strong style="color: #d32f2f;">⚠️ Security Notice:</strong><br>
                Never share this code with anyone. PhilCST staff will never ask for your OTP via email, call, or message.
            </div>

            <p style="font-size: 13px; color: #555; line-height: 1.6;">
                If you did not request this password change, please ignore this email and contact your administrator immediately.
            </p>
        </div>

        <div class="footer">
            <div class="footer-text">
                <p style="margin-bottom: 6px;">© {{ date('Y') }} Philcst Alumni Connect. All rights reserved.</p>
                <p>Philippine College of Science and Technology</p>
                <p style="margin-top: 12px;">
                    For support, contact: <a href="mailto:admin@philcst.edu.ph">admin@philcst.edu.ph</a>
                </p>
                <p style="margin-top: 8px; font-size: 11px; color: #a78bda;">
                    This is an automated message. Please do not reply to this email.
                </p>
            </div>
        </div>
    </div>
</body>
</html>