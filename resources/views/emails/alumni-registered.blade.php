<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Philcst Alumni Connect - Account Created</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
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
        
        .header h1 {
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 8px;
            letter-spacing: -0.5px;
        }
        
        .header p {
            font-size: 14px;
            opacity: 0.9;
            font-weight: 500;
        }
        
        .content {
            padding: 40px 30px;
        }
        
        .greeting {
            font-size: 16px;
            color: #333333;
            margin-bottom: 25px;
            line-height: 1.6;
        }
        
        .greeting strong {
            color: #7a3f91;
        }
        
        .success-message {
            background-color: #f0fdf4;
            border-left: 4px solid #10b981;
            padding: 15px;
            border-radius: 8px;
            margin: 25px 0;
            font-size: 14px;
            color: #047857;
        }
        
        .credentials-section {
            background-color: #f9f5ff;
            border: 2px dashed #d8b4fe;
            border-radius: 10px;
            padding: 25px;
            margin: 30px 0;
        }
        
        .credentials-label {
            font-size: 12px;
            font-weight: 700;
            color: #7a3f91;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 20px;
            display: block;
        }
        
        .credential-item {
            margin-bottom: 20px;
            padding-bottom: 20px;
            border-bottom: 1px solid #e9d5ff;
        }
        
        .credential-item:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }
        
        .credential-label {
            font-size: 12px;
            font-weight: 600;
            color: #666666;
            text-transform: uppercase;
            margin-bottom: 8px;
            display: block;
            letter-spacing: 0.5px;
        }
        
        .credential-value {
            font-size: 16px;
            font-weight: 700;
            color: #1f2937;
            font-family: 'Courier New', monospace;
            background-color: #ffffff;
            padding: 12px 15px;
            border-radius: 6px;
            border: 1px solid #e5e7eb;
            word-break: break-all;
        }
        
        .info-text {
            font-size: 13px;
            color: #666666;
            margin-top: 20px;
            line-height: 1.6;
            padding: 15px;
            background-color: #f5f3ff;
            border-radius: 6px;
            border-left: 3px solid #7a3f91;
        }
        
        .cta-button {
            display: inline-block;
            background-color: #7a3f91;
            color: #ffffff;
            padding: 14px 35px;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 600;
            margin-top: 25px;
            transition: all 0.3s ease;
            font-size: 14px;
            letter-spacing: 0.5px;
        }
        
        .cta-button:hover {
            background-color: #5a2d6f;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(122, 63, 145, 0.3);
        }
        
        .footer {
            background-color: #f9f5ff;
            padding: 25px 30px;
            text-align: center;
            border-top: 1px solid #e9d5ff;
        }
        
        .footer-text {
            font-size: 12px;
            color: #9333ea;
            line-height: 1.8;
        }
        
        .footer-text a {
            color: #7a3f91;
            text-decoration: none;
            font-weight: 600;
        }
        
        .divider {
            height: 1px;
            background-color: #e9d5ff;
            margin: 25px 0;
        }
        
        .highlight {
            color: #7a3f91;
            font-weight: 600;
        }
        
        .badge {
            display: inline-block;
            background-color: #7a3f91;
            color: #ffffff;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 15px;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <h1>🎓 Philcst Alumni Connect</h1>
            <p>Welcome to Our Alumni Community</p>
        </div>

        <!-- Content -->
        <div class="content">
            <div class="greeting">
                Hello <strong>{{ $alumni->name }}</strong>,
            </div>

            <div class="success-message">
                ✓ Your account has been created successfully! Welcome to Philcst Alumni Connect.
            </div>

            <p style="font-size: 14px; color: #555555; line-height: 1.6; margin-bottom: 20px;">
                Your student profile has been registered in our alumni management system. Below you'll find your login credentials to access your account.
            </p>

            <!-- Credentials Section -->
            <div class="credentials-section">
                <span class="credentials-label">📋 Your Account Credentials</span>

                <div class="credential-item">
                    <span class="credential-label">Student ID / Username</span>
                    <div class="credential-value">{{ $studentId }}</div>
                </div>

                <div class="credential-item">
                    <span class="credential-label">Temporary Password</span>
                    <div class="credential-value">{{ $temporaryPassword }}</div>
                </div>

                <div class="credential-item">
                    <span class="credential-label">Email Address</span>
                    <div class="credential-value">{{ $alumni->email }}</div>
                </div>
            </div>

            <!-- Important Info -->
            <div class="info-text">
                <strong style="color: #7a3f91;">⚠️ Important:</strong><br>
                Your temporary password is case-sensitive. Please change it after your first login. Keep your credentials secure and do not share them with anyone.
            </div>

            <!-- Student Info -->
            <div style="margin-top: 30px; padding: 20px; background-color: #fafafa; border-radius: 8px; border: 1px solid #eeeeee;">
                <h3 style="color: #333333; font-size: 14px; font-weight: 600; margin-bottom: 15px;">Your Profile Information</h3>
                <p style="font-size: 13px; color: #666666; margin-bottom: 10px;"><strong>Name:</strong> {{ $alumni->name }}</p>
                <p style="font-size: 13px; color: #666666; margin-bottom: 10px;"><strong>Course:</strong> {{ $alumni->course_name }} ({{ $alumni->course_code }})</p>
                <p style="font-size: 13px; color: #666666;"><strong>Graduation Year:</strong> {{ $alumni->batch }}</p>
            </div>

            <p style="font-size: 14px; color: #555555; line-height: 1.6; margin-top: 25px;">
                If you did not create this account or have any questions, please contact our alumni support team immediately.
            </p>
        </div>

        <!-- Footer -->
        <div class="footer">
            <div class="footer-text">
                <p style="margin-bottom: 10px;">© {{ date('Y') }} Philcst Alumni Connect. All rights reserved.</p>
                <p>
                    Philippine College of Science and Technology
                </p>
                <p style="margin-top: 15px; font-size: 11px; color: #a78bda;">
                    This is an automated message. Please do not reply to this email.
                </p>
            </div>
        </div>

    </div>
</body>
</html>