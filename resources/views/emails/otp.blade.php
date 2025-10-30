<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OTP Verification</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f4f7fa;
            margin: 0;
            padding: 0;
        }
        .email-container {
            max-width: 600px;
            margin: 40px auto;
            background-color: #ffffff;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 30px;
            text-align: center;
            color: #ffffff;
        }
        .header h1 {
            margin: 0;
            font-size: 24px;
            font-weight: 600;
        }
        .content {
            padding: 40px 30px;
        }
        .greeting {
            font-size: 18px;
            color: #333333;
            margin-bottom: 20px;
        }
        .message {
            font-size: 14px;
            color: #666666;
            line-height: 1.6;
            margin-bottom: 30px;
        }
        .otp-box {
            background-color: #f8f9fa;
            border: 2px dashed #667eea;
            border-radius: 8px;
            padding: 25px;
            text-align: center;
            margin: 30px 0;
        }
        .otp-label {
            font-size: 12px;
            color: #666666;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 10px;
        }
        .otp-code {
            font-size: 36px;
            font-weight: bold;
            color: #667eea;
            letter-spacing: 8px;
            font-family: 'Courier New', monospace;
        }
        .expiry-info {
            font-size: 12px;
            color: #999999;
            margin-top: 15px;
        }
        .warning {
            background-color: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 15px;
            margin: 20px 0;
            font-size: 13px;
            color: #856404;
            border-radius: 4px;
        }
        .footer {
            background-color: #f8f9fa;
            padding: 20px 30px;
            text-align: center;
            font-size: 12px;
            color: #999999;
            border-top: 1px solid #e9ecef;
        }
        .footer a {
            color: #667eea;
            text-decoration: none;
        }
    </style>
</head>
<body>
    <div class="email-container">
        <div class="header">
            <h1>🔐 OTP Verification</h1>
        </div>
        
        <div class="content">
            <div class="greeting">
                Hello, <strong>{{ $fullName }}</strong>!
            </div>
            
            <div class="message">
                @if($purpose === 'reset_password')
                    You have requested to reset your password. Please use the OTP code below to complete the password reset process.
                @else
                    You are attempting to login to your account. Please use the OTP code below to verify your identity.
                @endif
            </div>
            
            <div class="otp-box">
                <div class="otp-label">Your OTP Code</div>
                <div class="otp-code">{{ $otpCode }}</div>
                <div class="expiry-info">
                    ⏱️ This code will expire in {{ $expiresIn }} seconds
                </div>
            </div>
            
            <div class="warning">
                <strong>⚠️ Security Notice:</strong><br>
                Never share this OTP code with anyone. Our team will never ask for your OTP code via phone, email, or any other method.
            </div>
            
            <div class="message">
                If you did not request this OTP code, please ignore this email or contact our support team immediately.
            </div>
        </div>
        
        <div class="footer">
            <p>
                This is an automated message, please do not reply to this email.<br>
                For support, contact us at <a href="mailto:support@example.com">support@example.com</a>
            </p>
            <p style="margin-top: 15px;">
                © {{ date('Y') }} {{ config('app.name') }}. All rights reserved.
            </p>
        </div>
    </div>
</body>
</html>