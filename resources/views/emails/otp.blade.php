<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; background: #f4f4f4; margin: 0; padding: 0; }
        .container { max-width: 480px; margin: 40px auto; background: #fff; border-radius: 8px; padding: 36px; }
        .otp { font-size: 36px; font-weight: bold; letter-spacing: 8px; color: #333; text-align: center; margin: 24px 0; }
        .note { color: #888; font-size: 13px; text-align: center; }
    </style>
</head>
<body>
    <div class="container">
        <h2 style="text-align:center; color:#333;">Email Verification</h2>
        <p>Use the OTP below to verify your email address. It expires in <strong>10 minutes</strong>.</p>
        <div class="otp">{{ $otp }}</div>
        <p class="note">If you did not request this, please ignore this email.</p>
    </div>
</body>
</html>
