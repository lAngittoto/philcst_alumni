<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Director Account Created</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: #f0eaf6; padding: 20px; }
        .container { max-width: 600px; margin: 0 auto; background-color: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 10px 25px rgba(122, 63, 145, 0.18); }
        .header { background: linear-gradient(135deg, #7a3f91 0%, #4e2669 100%); padding: 40px 30px; text-align: center; color: #ffffff; }
        .header-badge { display: inline-block; background: rgba(255,255,255,0.15); border: 1px solid rgba(255,255,255,0.3); border-radius: 30px; padding: 5px 16px; font-size: 11px; font-weight: 700; letter-spacing: 1.5px; text-transform: uppercase; margin-bottom: 14px; color: #f0e6fa; }
        .header h1 { font-size: 26px; font-weight: 700; margin-bottom: 6px; letter-spacing: -0.5px; }
        .header p { font-size: 13px; opacity: 0.85; font-weight: 400; }
        .content { padding: 40px 30px; }
        .greeting { font-size: 15px; color: #333; margin-bottom: 20px; line-height: 1.6; }
        .greeting strong { color: #7a3f91; }
        .success-message { background-color: #f0fdf4; border-left: 4px solid #10b981; padding: 13px 15px; border-radius: 8px; margin: 0 0 20px; font-size: 13px; color: #047857; }
        .body-text { font-size: 13px; color: #555; line-height: 1.7; margin-bottom: 20px; }
        .credentials-section { background-color: #f9f5ff; border: 2px dashed #c084e8; border-radius: 10px; padding: 25px; margin: 0 0 20px; }
        .credentials-label { font-size: 11px; font-weight: 700; color: #7a3f91; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 20px; display: block; }
        .credential-item { margin-bottom: 18px; padding-bottom: 18px; border-bottom: 1px solid #e9d5ff; }
        .credential-item:last-child { border-bottom: none; margin-bottom: 0; padding-bottom: 0; }
        .credential-label { font-size: 11px; font-weight: 600; color: #666; text-transform: uppercase; margin-bottom: 6px; display: block; letter-spacing: 0.5px; }
        .credential-value { font-size: 15px; font-weight: 700; color: #1f2937; font-family: 'Courier New', monospace; background-color: #fff; padding: 11px 14px; border-radius: 6px; border: 1px solid #e5e7eb; word-break: break-all; }
        .profile-box { background-color: #fafafa; border-radius: 8px; border: 1px solid #eee; padding: 20px; margin-bottom: 20px; }
        .profile-box h3 { font-size: 13px; color: #333; font-weight: 600; margin-bottom: 12px; padding-bottom: 8px; border-bottom: 1px solid #eee; }
        .profile-box p { font-size: 12px; color: #666; margin-bottom: 8px; }
        .profile-box p:last-child { margin-bottom: 0; }
        .profile-box span { color: #7a3f91; font-weight: 600; }
        .warning-box { font-size: 12px; color: #666; line-height: 1.6; padding: 14px; background-color: #fffbeb; border-radius: 6px; border-left: 3px solid #f59e0b; margin-bottom: 20px; }
        .warning-box strong { color: #b45309; }
        .steps-box { background-color: #f5eef9; border-radius: 8px; border: 1px solid #e9d5ff; padding: 20px; margin-bottom: 20px; }
        .steps-box h3 { font-size: 12px; font-weight: 700; color: #7a3f91; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 12px; }
        .step { display: flex; align-items: flex-start; gap: 10px; margin-bottom: 10px; font-size: 12px; color: #555; line-height: 1.5; }
        .step:last-child { margin-bottom: 0; }
        .step-num { width: 20px; height: 20px; background: #7a3f91; color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 10px; font-weight: 700; flex-shrink: 0; margin-top: 1px; }
        .footer { background-color: #f9f5ff; padding: 25px 30px; text-align: center; border-top: 1px solid #e9d5ff; }
        .footer-text { font-size: 12px; color: #9333ea; line-height: 1.8; }
        .divider { height: 1px; background: linear-gradient(to right, transparent, #e9d5ff, transparent); margin: 20px 0; }
    </style>
</head>
<body>
    <div class="container">

        {{-- Header --}}
        <div class="header">
            <div class="header-badge">🎓 Philcst Alumni Connect</div>
            <h1>Director Account Created</h1>
            <p>Philippine College of Science and Technology</p>
        </div>

        <div class="content">
            <div class="greeting">
                Hello, <strong>{{ $fullName }}</strong>!
            </div>

            <div class="success-message">
                ✓ Your director account has been successfully created in Philcst Alumni Connect.
            </div>

            <p class="body-text">
                You have been registered as a <strong>Director</strong> in the Philcst Alumni Connect system.
                Below are your login credentials. Please keep them safe and confidential.
            </p>

            {{-- Credentials --}}
            <div class="credentials-section">
                <span class="credentials-label">🔐 Your Login Credentials</span>

                <div class="credential-item">
                    <span class="credential-label">Username (used to log in)</span>
                    <div class="credential-value">{{ $username }}</div>
                </div>

                <div class="credential-item">
                    <span class="credential-label">Temporary Password</span>
                    <div class="credential-value">{{ $tempPassword }}</div>
                </div>

                @if(!empty($email))
                <div class="credential-item">
                    <span class="credential-label">Email Address (on record)</span>
                    <div class="credential-value">{{ $email }}</div>
                </div>
                @endif
            </div>

            {{-- Profile summary --}}
            <div class="profile-box">
                <h3>Your Director Profile</h3>
                <p><span>Full Name:</span> {{ $fullName }}</p>
                @if(!empty($email))
                <p><span>Email:</span> {{ $email }}</p>
                @endif
                <p><span>Role:</span> Director</p>
                <p><span>Account Status:</span> Active (pending password change)</p>
            </div>

            {{-- Steps --}}
            <div class="steps-box">
                <h3>📋 Next Steps</h3>
                <div class="step">
                    <div class="step-num">1</div>
                    <div>Go to the Philcst Alumni Connect login page and enter your <strong>username</strong> and the temporary password above.</div>
                </div>
                <div class="step">
                    <div class="step-num">2</div>
                    <div>You will be prompted to <strong>create a new, secure password</strong> before accessing your dashboard.</div>
                </div>
                <div class="step">
                    <div class="step-num">3</div>
                    <div>Once your password is set, you will have full access to the Director portal.</div>
                </div>
            </div>

            {{-- Warning --}}
            <div class="warning-box">
                <strong>⚠️ Important Security Notice:</strong><br>
                Your temporary password is case-sensitive and is valid for first-time login only.
                You will be required to change it immediately upon logging in.
                Please do not share your credentials with anyone.
            </div>

            <div class="divider"></div>

            <p style="font-size: 13px; color: #555; line-height: 1.6;">
                If you did not expect this account or have any questions, please contact the system administrator immediately.
            </p>
        </div>

        {{-- Footer --}}
        <div class="footer">
            <div class="footer-text">
                <p style="margin-bottom: 6px;">© {{ date('Y') }} Philcst Alumni Connect. All rights reserved.</p>
                <p>Philippine College of Science and Technology</p>
                <p style="margin-top: 12px; font-size: 11px; color: #a78bda;">
                    This is an automated message. Please do not reply to this email.
                </p>
            </div>
        </div>

    </div>
</body>
</html>