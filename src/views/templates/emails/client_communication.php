<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($subject); ?></title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
            background-color: #f8f9fa;
        }
        .email-container {
            background: white;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }
        .header h1 {
            margin: 0;
            font-size: 24px;
            font-weight: 600;
        }
        .content {
            padding: 30px;
        }
        .greeting {
            font-size: 18px;
            margin-bottom: 20px;
            color: #2c3e50;
        }
        .message-body {
            font-size: 16px;
            line-height: 1.8;
            margin-bottom: 30px;
            white-space: pre-line;
        }
        .order-info {
            background: #f8f9fa;
            border-left: 4px solid #667eea;
            padding: 15px;
            margin: 20px 0;
            border-radius: 0 5px 5px 0;
        }
        .order-info strong {
            color: #667eea;
        }
        .footer {
            background: #2c3e50;
            color: white;
            padding: 20px;
            text-align: center;
            font-size: 14px;
        }
        .footer a {
            color: #667eea;
            text-decoration: none;
        }
        .footer a:hover {
            text-decoration: underline;
        }
        .cta-button {
            display: inline-block;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 12px 24px;
            text-decoration: none;
            border-radius: 25px;
            font-weight: 600;
            margin: 20px 0;
            transition: transform 0.2s;
        }
        .cta-button:hover {
            transform: translateY(-2px);
        }
    </style>
</head>
<body>
    <div class="email-container">
        <div class="header">
            <h1>📧 <?php echo htmlspecialchars($companyName); ?></h1>
        </div>
        
        <div class="content">
            <div class="greeting">
                Hello <?php echo htmlspecialchars($clientName); ?>,
            </div>
            
            <div class="message-body">
                <?php echo htmlspecialchars($body); ?>
            </div>
            
            <div class="order-info">
                <strong>Order Reference:</strong> VNV341<?php echo htmlspecialchars($orderId); ?>
            </div>
            
            <p style="margin-top: 30px;">
                If you have any questions or need assistance, please don't hesitate to contact us. 
                We're here to help make your event planning experience smooth and enjoyable.
            </p>
            
            <p>
                Best regards,<br>
                <strong>VNV-Events Team</strong>
            </p>
        </div>
        
        <div class="footer">
            <p>
                This email was sent from VNV-Events regarding your event order.<br>
                <a href="https://vnvevents.com/">Visit our website</a> |
                <a href="mailto:info@vnvevents.com">Contact VNV Events</a>
            </p>
            <p style="margin-top: 15px; font-size: 12px; opacity: 0.8;">
                © <?php echo date('Y'); ?> VNV-Events. All rights reserved.
            </p>
        </div>
    </div>
</body>
</html>
