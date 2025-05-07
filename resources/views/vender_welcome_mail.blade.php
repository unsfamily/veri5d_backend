<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Welcome Vendor</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f8f9fa;
            padding: 30px;
        }
        .container {
            background-color: #ffffff;
            padding: 25px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            max-width: 600px;
            margin: 0 auto;
        }
        .button {
            background-color: #4CAF50;
            color: white;
            padding: 12px 20px;
            text-decoration: none;
            border-radius: 5px;
            display: inline-block;
            margin-top: 20px;
        }
        .footer {
            margin-top: 30px;
            font-size: 12px;
            color: #666;
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="container">
        <h2>Hi {{ $loki['name'] }},</h2>

        <p>Thank you for registering your shop <strong>{{ $loki['shop_name'] }}</strong> with us!</p>

        <p>We have received your details and our team will contact you shortly.</p>
        
        <p>If you have any urgent queries, please Veri5d@gmail.com.</p>

        {{-- <a href="#" class="button">Visit Our Website</a> --}}

        <div class="footer">
            © {{ date('Y') }} Veri5d. All rights reserved.
        </div>
    </div>
</body>
</html>
