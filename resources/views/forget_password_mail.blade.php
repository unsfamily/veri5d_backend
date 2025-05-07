<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>Password Reset OTP</title>
        <style>
            body {
                font-family: 'Arial', sans-serif;
                background-color: #f4f4f4;
                margin: 0;
                padding: 30px;
            }
            .container {
                background: #fff;
                padding: 30px;
                max-width: 600px;
                margin: 0 auto;
                border-radius: 8px;
                box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            }
            .otp {
                font-size: 30px;
                font-weight: bold;
                color: #4CAF50;
                margin: 20px 0;
            }
            .footer {
                margin-top: 30px;
                text-align: center;
                font-size: 12px;
                color: #888;
            }
        </style>
    </head>
    <body>
        <div class="container">
            <h2>Password Reset Request</h2>
            <p>Hello,</p>
            <p>We received a request to reset your password. Please use the OTP below to verify your identity:</p>

            <div class="otp">{{ $otp }}</div>

            <p>This OTP is valid for the next 10 minutes. Please do not share it with anyone.</p>

            <p>If you didn't request a password reset, you can safely ignore this email.</p>

            <div class="footer">
                © {{ date('Y') }} Veri5d. All rights reserved.
            </div>
        </div>
    </body>
</html>
