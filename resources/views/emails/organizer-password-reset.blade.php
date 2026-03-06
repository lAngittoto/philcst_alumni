<!-- resources/views/emails/organizer-password-reset.blade.php -->

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Password Change Verification</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; background: #f9f9f9; }
        .email-wrapper { background: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
        .header { background: linear-gradient(135deg, #7a3f91 0%, #2b0d3e 100%); color: white; padding: 30px 20px; text-align: center; }
        .header h1 { font-size: 24px; margin-bottom: 5px; }
        .header p { font-size: 14px; opacity: 0.9; }
        .content { padding: 30px 20px; }
        .greeting { font-size: 16px; margin-bottom: 20px; }
        .otp-section { background: #f0e6f7; border-left: 4px solid #7a3f91; padding: 20px; border-radius: 4px; margin: 20px 0; text-align: center; }
        .otp-code { font-size: 32px; font-weight: bold; letter-spacing: 4px; color: #2b0d3e; font-family: 'Courier New', monospace; margin: 10px 0; }
        .otp-expires { font-size: 13px; color: #666; margin-top: 10px; }
        .info-box { background: #f5f5f5; padding: 15px; border-radius: 4px; margin: 20px 0; font-size: 14px; }
        .info-box strong { color: #2b0d3e; }
        .footer { background: #f9f9f9; padding: 20px; text-align: center; border-top: 1px solid #eee; font-size: 12px; color: #666; }
        .warning { color: #d32f2f; font-weight: bold; }
        a { color: #7a3f91; text-decoration: none; }
        a:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <div class="container">
        <div class="email-wrapper">
            <!-- Header -->
            <div class="header">
                <h1>🔐 Password Change Verification</h1>
                <p>PhilCST Alumni Portal</p>
            </div>

            <!-- Content -->
            <div class="content">
                <div class="greeting">
                    Hello <strong>{{ $organizer->name }}</strong>,
                </div>

                <p>You initiated a password change request for your organizer account. To complete this process, please verify your identity using the One-Time Password (OTP) below:</p>

                <!-- OTP Section -->
                <div class="otp-section">
                    <p style="margin-bottom: 10px; color: #666;">Your verification code:</p>
                    <div class="otp-code">{{ $otp }}</div>
                    <p class="otp-expires">⏱️ This code expires in 10 minutes</p>
                </div>

                <!-- Instructions -->
                <div class="info-box">
                    <strong>✓ Instructions:</strong>
                    <ol style="margin-left: 20px; margin-top: 10px;">
                        <li>Enter the 6-digit code above in the password change form</li>
                        <li>Complete your new password setup</li>
                        <li>Log in with your new password</li>
                    </ol>
                </div>

                <!-- Security Notice -->
                <div class="info-box" style="background: #fff3cd; border-left-color: #ff9800;">
                    <strong style="color: #d32f2f;">⚠️ Security Notice:</strong>
                    <p style="margin-top: 5px;">
                        Never share this code with anyone. PhilCST staff will never ask for your OTP via email, call, or message.
                    </p>
                </div>

                <p style="margin-top: 20px; font-size: 14px;">
                    If you did not request this password change, please ignore this email and contact your administrator immediately.
                </p>
            </div>

            <!-- Footer -->
            <div class="footer">
                <p>&copy; {{ date('Y') }} Philippine College of Science and Technology</p>
                <p>This is an automated message. Please do not reply to this email.</p>
                <p>For support, contact: <a href="mailto:admin@philcst.edu.ph">admin@philcst.edu.ph</a></p>
            </div>
        </div>
    </div>
</body>
</html>