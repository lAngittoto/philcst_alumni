<div style="font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 40px 0; margin: 0;">
    <div style="max-width: 600px; margin: 0 auto; background-color: white; border-radius: 12px; box-shadow: 0 10px 40px rgba(0,0,0,0.15); overflow: hidden;">
        
        {{-- Header with Gradient --}}
        <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 50px 30px; text-align: center; color: white;">
            <div style="font-size: 48px; margin-bottom: 15px;">
                <i class="fa-solid fa-graduation-cap"></i>🎓
            </div>
            <h1 style="margin: 0 0 10px 0; font-size: 32px; font-weight: bold; letter-spacing: -0.5px;">
                Welcome to PHILCST
            </h1>
            <p style="margin: 0; font-size: 16px; opacity: 0.95; font-weight: 300;">
                Alumni Management System
            </p>
        </div>

        {{-- Main Content --}}
        <div style="padding: 50px 40px;">
            
            {{-- Greeting --}}
            <p style="margin: 0 0 30px 0; font-size: 18px; color: #2b0d3e; font-weight: 600;">
                Hi <strong>{{ $name }}</strong>,
            </p>

            {{-- Welcome Message --}}
            <p style="margin: 0 0 25px 0; font-size: 15px; color: #555; line-height: 1.8;">
                Your organizer account has been successfully created in the PHILCST Alumni Management System. 
                Below are your login credentials to get started.
            </p>

            {{-- Credentials Box --}}
            <div style="background: linear-gradient(135deg, #f5f7fa 0%, #f0f4ff 100%); border: 2px solid #667eea; border-radius: 12px; padding: 30px; margin: 30px 0; position: relative;">
                
                <div style="text-align: center; margin-bottom: 25px;">
                    <div style="font-size: 24px; color: #667eea; margin-bottom: 8px;">
                        🔐 Login Credentials
                    </div>
                    <p style="margin: 0; font-size: 12px; color: #999; text-transform: uppercase; letter-spacing: 1px;">
                        Use these to access the system
                    </p>
                </div>

                {{-- ID Number Field --}}
                <div style="margin-bottom: 20px; padding-bottom: 20px; border-bottom: 1px solid #ddd;">
                    <p style="margin: 0 0 8px 0; font-size: 12px; text-transform: uppercase; color: #667eea; font-weight: bold; letter-spacing: 1px;">
                        Teacher ID
                    </p>
                    <div style="background: white; border: 2px solid #667eea; border-radius: 8px; padding: 15px; text-align: center;">
                        <p style="margin: 0; font-size: 24px; font-weight: bold; color: #2b0d3e; font-family: 'Courier New', monospace; letter-spacing: 2px;">
                            {{ $idNumber }}
                        </p>
                    </div>
                </div>

                {{-- Temporary Password Field --}}
                <div style="margin-bottom: 15px;">
                    <p style="margin: 0 0 8px 0; font-size: 12px; text-transform: uppercase; color: #667eea; font-weight: bold; letter-spacing: 1px;">
                        Temporary Password
                    </p>
                    <div style="background: white; border: 2px solid #667eea; border-radius: 8px; padding: 15px; text-align: center;">
                        <p style="margin: 0; font-size: 20px; font-weight: bold; color: #2b0d3e; font-family: 'Courier New', monospace; letter-spacing: 1px;">
                            {{ $tempPassword }}
                        </p>
                    </div>
                </div>

            </div>

            {{-- Important Notes --}}
            <div style="background-color: #fff3cd; border-left: 5px solid #ffc107; padding: 20px; border-radius: 6px; margin: 30px 0;">
                <p style="margin: 0; font-size: 13px; color: #856404; line-height: 1.8;">
                    <strong>⚠️ Important:</strong> This temporary password is valid only for your first login. 
                    You will be required to change it immediately upon login. Please choose a strong password that includes uppercase letters, lowercase letters, numbers, and special characters.
                </p>
            </div>

            {{-- Steps --}}
            <div style="margin: 35px 0;">
                <p style="margin: 0 0 20px 0; font-size: 13px; color: #2b0d3e; font-weight: bold; text-transform: uppercase; letter-spacing: 0.5px;">
                    Next Steps:
                </p>
                <ol style="margin: 0; padding: 0 0 0 25px; color: #555;">
                    <li style="margin-bottom: 12px; font-size: 13px; line-height: 1.6;">
                        Click the login button below or visit <strong>{{ $loginUrl }}</strong>
                    </li>
                    <li style="margin-bottom: 12px; font-size: 13px; line-height: 1.6;">
                        Enter your Teacher ID: <strong>{{ $idNumber }}</strong>
                    </li>
                    <li style="margin-bottom: 12px; font-size: 13px; line-height: 1.6;">
                        Enter the temporary password above
                    </li>
                    <li style="margin-bottom: 12px; font-size: 13px; line-height: 1.6;">
                        You will be prompted to create a new, permanent password
                    </li>
                    <li style="font-size: 13px; line-height: 1.6;">
                        Verify with the OTP sent to this email
                    </li>
                </ol>
            </div>

            {{-- Login Button --}}
            <div style="text-align: center; margin: 35px 0;">
                <a href="{{ $loginUrl }}" 
                   style="display: inline-block; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; text-decoration: none; padding: 16px 40px; border-radius: 8px; font-weight: bold; font-size: 14px; text-transform: uppercase; letter-spacing: 1px; box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3); transition: all 0.3s ease;">
                    🚀 Login to System
                </a>
            </div>

            {{-- Security Notice --}}
            <div style="background-color: #e8f4f8; border-left: 5px solid #17a2b8; padding: 20px; border-radius: 6px; margin: 30px 0;">
                <p style="margin: 0; font-size: 12px; color: #0c5460; line-height: 1.8;">
                    <strong>🔒 Security Notice:</strong> Do not share your login credentials with anyone. 
                    This email contains sensitive information. If you did not request this account, 
                    please contact the system administrator immediately.
                </p>
            </div>

            {{-- Contact Information --}}
            <p style="margin: 30px 0 0 0; font-size: 13px; color: #999; line-height: 1.8;">
                <strong>Account Details:</strong><br>
                Name: {{ $name }}<br>
                Email: {{ $email }}<br>
                Department: {{ $department }}
            </p>

            <p style="margin: 20px 0 0 0; font-size: 12px; color: #999;">
                If you have any questions or need technical support, please contact the IT Department.
            </p>

        </div>

        {{-- Footer --}}
        <div style="background-color: #f8f9fa; padding: 30px 40px; border-top: 1px solid #e0e0e0; text-align: center;">
            <p style="margin: 0 0 15px 0; font-size: 13px; color: #666;">
                <strong>Philippine College of Science and Technology</strong><br>
                Alumni Management System
            </p>
            <p style="margin: 0 0 10px 0; font-size: 11px; color: #999;">
                © {{ date('Y') }} PHILCST. All rights reserved.
            </p>
            <p style="margin: 0; font-size: 11px; color: #bbb;">
                This is an automated email. Please do not reply directly to this message.
            </p>
        </div>

    </div>

    {{-- Additional Security Note --}}
    <div style="text-align: center; margin-top: 30px; padding: 0 20px;">
        <p style="margin: 0; font-size: 11px; color: #999; line-height: 1.8;">
            This email was sent because an organizer account was created for you in the PHILCST Alumni System.<br>
            If this was not you, please ignore this email or contact your administrator immediately.
        </p>
    </div>

</div>