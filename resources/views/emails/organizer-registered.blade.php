<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Welcome to PHILCST Alumni System</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Arial, sans-serif; background: #f3f0f8; padding: 40px 20px; }
        .wrapper { max-width: 600px; margin: 0 auto; }
        .card { background: #ffffff; border-radius: 20px; overflow: hidden; box-shadow: 0 8px 30px rgba(122,63,145,0.12); }

        /* Header */
        .header { background: linear-gradient(135deg, #7a3f91 0%, #5a2d6f 100%); padding: 40px 36px; text-align: center; }
        .header-icon { width: 72px; height: 72px; background: rgba(255,255,255,0.15); border-radius: 50%; margin: 0 auto 16px; display: flex; align-items: center; justify-content: center; }
        .header-icon svg { width: 36px; height: 36px; fill: white; }
        .header h1 { color: #ffffff; font-size: 24px; font-weight: 800; margin-bottom: 6px; }
        .header p { color: rgba(255,255,255,0.75); font-size: 14px; }

        /* Badge */
        .badge-row { background: #f8f0ff; border-bottom: 1px solid #ede0f8; padding: 14px 36px; display: flex; align-items: center; gap: 10px; }
        .badge { background: #7a3f91; color: white; font-size: 11px; font-weight: 800; padding: 4px 12px; border-radius: 100px; letter-spacing: 0.5px; text-transform: uppercase; }
        .badge-label { color: #7a3f91; font-size: 13px; font-weight: 600; }

        /* Body */
        .body { padding: 32px 36px; }
        .greeting { font-size: 18px; font-weight: 700; color: #1f1235; margin-bottom: 10px; }
        .intro { font-size: 14px; color: #6b7280; line-height: 1.7; margin-bottom: 24px; }

        /* Credentials Box */
        .creds-box { background: #faf5ff; border: 2px solid #e9d5ff; border-radius: 14px; padding: 24px; margin-bottom: 24px; }
        .creds-title { font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: 1px; color: #9ca3af; margin-bottom: 16px; }
        .cred-row { display: flex; align-items: flex-start; gap: 12px; padding: 10px 0; border-bottom: 1px solid #f3e8ff; }
        .cred-row:last-child { border-bottom: none; }
        .cred-icon { width: 32px; height: 32px; background: #ede9fe; border-radius: 8px; display: flex; align-items: center; justify-content: center; shrink: 0; flex-shrink: 0; }
        .cred-icon svg { width: 16px; height: 16px; fill: #7a3f91; }
        .cred-info { flex: 1; }
        .cred-label { font-size: 11px; font-weight: 700; color: #9ca3af; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 2px; }
        .cred-value { font-size: 14px; font-weight: 700; color: #1f1235; }
        .cred-value.password { font-family: 'Courier New', monospace; font-size: 15px; color: #7a3f91; letter-spacing: 1px; background: #ede9fe; padding: 2px 8px; border-radius: 6px; display: inline-block; }

        /* Status Badge */
        .status-active { display: inline-flex; align-items: center; gap: 5px; background: #d1fae5; color: #065f46; font-size: 12px; font-weight: 700; padding: 3px 10px; border-radius: 100px; border: 1px solid #a7f3d0; }
        .status-dot { width: 6px; height: 6px; background: #10b981; border-radius: 50%; }

        /* Warning box */
        .warning { background: #fffbeb; border: 1px solid #fde68a; border-radius: 12px; padding: 16px 20px; margin-bottom: 24px; display: flex; gap: 12px; align-items: flex-start; }
        .warning-icon { font-size: 18px; flex-shrink: 0; margin-top: 1px; }
        .warning p { font-size: 13px; color: #92400e; line-height: 1.6; }
        .warning strong { font-weight: 700; }

        /* CTA Button */
        .cta-row { text-align: center; margin-bottom: 28px; }
        .cta-btn { display: inline-block; background: linear-gradient(135deg, #7a3f91, #5a2d6f); color: white; text-decoration: none; font-weight: 800; font-size: 14px; padding: 14px 36px; border-radius: 12px; letter-spacing: 0.3px; }

        /* Divider */
        .divider { border: none; border-top: 1px solid #f3f4f6; margin: 4px 0 24px; }

        .note { font-size: 13px; color: #9ca3af; line-height: 1.7; }

        /* Footer */
        .footer { background: #f8f0ff; padding: 24px 36px; text-align: center; }
        .footer p { font-size: 12px; color: #9ca3af; line-height: 1.8; }
        .footer strong { color: #7a3f91; font-weight: 700; }
    </style>
</head>
<body>
<div class="wrapper">
    <div class="card">

        {{-- Header --}}
        <div class="header">
            <div class="header-icon">
                <svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></svg>
            </div>
            <h1>Welcome, {{ $organizer->name }}!</h1>
            <p>Your organizer account has been created successfully</p>
        </div>

        {{-- Role Badge --}}
        <div class="badge-row">
            <span class="badge">Organizer</span>
            <span class="badge-label">PHILCST Alumni System</span>
        </div>

        {{-- Body --}}
        <div class="body">
            <p class="greeting">Hello, {{ $organizer->name }}! 👋</p>
            <p class="intro">
                You have been registered as an <strong>Organizer</strong> in the PHILCST Alumni Management System.
                Below are your login credentials — please keep them secure and change your password upon first login.
            </p>

            {{-- Credentials --}}
            <div class="creds-box">
                <div class="creds-title">Your Login Credentials</div>

                <div class="cred-row">
                    <div class="cred-icon">
                        <svg viewBox="0 0 24 24"><path d="M20 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4l-8 5-8-5V6l8 5 8-5v2z"/></svg>
                    </div>
                    <div class="cred-info">
                        <div class="cred-label">Email Address</div>
                        <div class="cred-value">{{ $organizer->email }}</div>
                    </div>
                </div>

                <div class="cred-row">
                    <div class="cred-icon">
                        <svg viewBox="0 0 24 24"><path d="M18 8h-1V6c0-2.76-2.24-5-5-5S7 3.24 7 6v2H6c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V10c0-1.1-.9-2-2-2zm-6 9c-1.1 0-2-.9-2-2s.9-2 2-2 2 .9 2 2-.9 2-2 2zm3.1-9H8.9V6c0-1.71 1.39-3.1 3.1-3.1 1.71 0 3.1 1.39 3.1 3.1v2z"/></svg>
                    </div>
                    <div class="cred-info">
                        <div class="cred-label">Temporary Password</div>
                        <div class="cred-value password">{{ $password }}</div>
                    </div>
                </div>

                <div class="cred-row">
                    <div class="cred-icon">
                        <svg viewBox="0 0 24 24"><path d="M12 7V3H2v18h20V7H12zM6 19H4v-2h2v2zm0-4H4v-2h2v2zm0-4H4V9h2v2zm0-4H4V5h2v2zm4 12H8v-2h2v2zm0-4H8v-2h2v2zm0-4H8V9h2v2zm0-4H8V5h2v2zm10 12h-8v-2h2v-2h-2v-2h2v-2h-2V9h8v10zm-2-8h-2v2h2v-2zm0 4h-2v2h2v-2z"/></svg>
                    </div>
                    <div class="cred-info">
                        <div class="cred-label">Department</div>
                        <div class="cred-value">{{ $organizer->department }}</div>
                    </div>
                </div>

                <div class="cred-row">
                    <div class="cred-icon">
                        <svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></svg>
                    </div>
                    <div class="cred-info">
                        <div class="cred-label">Account Status</div>
                        <div class="cred-value">
                            <span class="status-active"><span class="status-dot"></span> {{ $organizer->status }}</span>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Warning --}}
            <div class="warning">
                <div class="warning-icon">⚠️</div>
                <p><strong>Important:</strong> This is a temporary password. Please log in and change it immediately. Do not share your credentials with anyone.</p>
            </div>

            {{-- Footer note --}}
            <hr class="divider">
            <p class="note">If you have any questions or did not expect this email, please contact your system administrator. This is an automated message — please do not reply directly to this email.</p>
        </div>

        {{-- Footer --}}
        <div class="footer">
            <p>&copy; {{ date('Y') }} <strong>PHILCST Alumni System</strong>. All rights reserved.</p>
            <p>Philippine College of Science and Technology</p>
        </div>

    </div>
</div>
</body>
</html>