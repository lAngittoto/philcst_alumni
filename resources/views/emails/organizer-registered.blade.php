<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Organizer Account Created</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: #f3f0f7; padding: 20px; }
        .container { max-width: 600px; margin: 0 auto; background-color: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 10px 25px rgba(122, 63, 145, 0.15); }
        .header { background: linear-gradient(135deg, #7a3f91 0%, #5a2d6f 100%); padding: 40px 30px; text-align: center; color: #ffffff; }
        .header h1 { font-size: 26px; font-weight: 700; margin-bottom: 6px; letter-spacing: -0.5px; }
        .header p { font-size: 13px; opacity: 0.88; font-weight: 400; }
        .content { padding: 40px 30px; }
        .greeting { font-size: 15px; color: #333; margin-bottom: 20px; line-height: 1.6; }
        .greeting strong { color: #7a3f91; }
        .success-message { background-color: #f0fdf4; border-left: 4px solid #10b981; padding: 13px 15px; border-radius: 8px; margin: 0 0 20px; font-size: 13px; color: #047857; }
        .body-text { font-size: 13px; color: #555; line-height: 1.7; margin-bottom: 20px; }
        .credentials-section { background-color: #f9f5ff; border: 2px dashed #d8b4fe; border-radius: 10px; padding: 25px; margin: 0 0 20px; }
        .credentials-label { font-size: 11px; font-weight: 700; color: #7a3f91; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 20px; display: block; }
        .credential-item { margin-bottom: 18px; padding-bottom: 18px; border-bottom: 1px solid #e9d5ff; }
        .credential-item:last-child { border-bottom: none; margin-bottom: 0; padding-bottom: 0; }
        .credential-label { font-size: 11px; font-weight: 600; color: #666; text-transform: uppercase; margin-bottom: 6px; display: block; letter-spacing: 0.5px; }
        .credential-value { font-size: 15px; font-weight: 700; color: #1f2937; font-family: 'Courier New', monospace; background-color: #fff; padding: 11px 14px; border-radius: 6px; border: 1px solid #e5e7eb; word-break: break-all; }
        .profile-box { background-color: #fafafa; border-radius: 8px; border: 1px solid #eee; padding: 20px; margin-bottom: 20px; }
        .profile-box h3 { font-size: 13px; color: #333; font-weight: 600; margin-bottom: 12px; }
        .profile-box p { font-size: 12px; color: #666; margin-bottom: 8px; }
        .profile-box p:last-child { margin-bottom: 0; }
        .info-text { font-size: 12px; color: #666; line-height: 1.6; padding: 14px; background-color: #f5f3ff; border-radius: 6px; border-left: 3px solid #7a3f91; margin-bottom: 20px; }
        .footer { background-color: #f9f5ff; padding: 25px 30px; text-align: center; border-top: 1px solid #e9d5ff; }
        .footer-text { font-size: 12px; color: #9333ea; line-height: 1.8; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🎓 Philcst Alumni Connect</h1>
            <p>Philippine College of Science and Technology</p>
        </div>

        <div class="content">
            <div class="greeting">Hello <strong>{{ $name }}</strong>,</div>

            <div class="success-message">✓ Your organizer account has been successfully created in Philcst Alumni Connect.</div>

            <p class="body-text">Below are your login credentials to access the system.</p>

            <div class="credentials-section">
                <span class="credentials-label">📋 Your Account Credentials</span>

                <div class="credential-item">
                    <span class="credential-label">Teacher ID / Username</span>
                    <div class="credential-value">{{ $idNumber }}</div>
                </div>

                <div class="credential-item">
                    <span class="credential-label">Temporary Password</span>
                    <div class="credential-value">{{ $tempPassword }}</div>
                </div>

                <div class="credential-item">
                    <span class="credential-label">Email Address</span>
                    <div class="credential-value">{{ $email }}</div>
                </div>
            </div>

            <div class="profile-box">
                <h3>Your Account Details</h3>
                <p><strong>Name:</strong> {{ $name }}</p>
                <p><strong>Email:</strong> {{ $email }}</p>
                <p><strong>Department:</strong> {{ $department }}</p>
            </div>

            <div class="info-text">
                <strong style="color: #7a3f91;">⚠️ Important:</strong><br>
                Your temporary password is case-sensitive. Please change it after your first login. Keep your credentials secure and do not share them with anyone.
            </div>

            <p style="font-size: 13px; color: #555; line-height: 1.6;">If you did not request this account or have any questions, please contact the system administrator immediately.</p>
        </div>

        <div class="footer">
            <div class="footer-text">
                <p style="margin-bottom: 6px;">© {{ date('Y') }} Philcst Alumni Connect. All rights reserved.</p>
                <p>Philippine College of Science and Technology</p>
                <p style="margin-top: 12px; font-size: 11px; color: #a78bda;">This is an automated message. Please do not reply to this email.</p>
            </div>
        </div>
    </div>
</body>
</html>