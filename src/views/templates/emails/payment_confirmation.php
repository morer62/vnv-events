<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Confirmation</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
            padding: 0;
            background-color: #f4f4f4;
            color: #333;
        }
        .container {
            max-width: 600px;
            margin: 0 auto;
            background-color: #ffffff;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        .header {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
            padding: 40px 30px;
            text-align: center;
        }
        .header h1 {
            margin: 0;
            font-size: 28px;
            font-weight: 300;
        }
        .header p {
            margin: 10px 0 0 0;
            opacity: 0.9;
            font-size: 16px;
        }
        .content {
            padding: 40px 30px;
        }
        .success-message {
            background-color: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
            padding: 20px;
            border-radius: 8px;
            margin: 20px 0;
            text-align: center;
        }
        .success-message h2 {
            margin: 0 0 10px 0;
            font-size: 24px;
        }
        .order-details {
            background-color: #f8f9fa;
            padding: 30px;
            border-radius: 8px;
            margin: 30px 0;
        }
        .order-details h3 {
            color: #495057;
            margin-top: 0;
            font-size: 20px;
            border-bottom: 2px solid #dee2e6;
            padding-bottom: 10px;
        }
        .detail-row {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid #e9ecef;
        }
        .detail-row:last-child {
            border-bottom: none;
        }
        .detail-label {
            font-weight: 500;
            color: #6c757d;
        }
        .detail-value {
            color: #495057;
        }
        .cta-button {
            display: inline-block;
            background: linear-gradient(135deg, #007bff 0%, #0056b3 100%);
            color: white !important;
            padding: 15px 30px;
            text-decoration: none;
            border-radius: 25px;
            font-weight: bold;
            margin: 20px 0;
            transition: transform 0.3s ease;
            border: none;
            font-size: 16px;
        }
        .cta-button:hover {
            transform: translateY(-2px);
        }
        .next-steps {
            background-color: #e3f2fd;
            padding: 20px;
            border-radius: 8px;
            margin: 20px 0;
        }
        .next-steps h4 {
            color: #1976d2;
            margin-top: 0;
        }
        .footer {
            background-color: #f8f9fa;
            padding: 30px;
            text-align: center;
            border-top: 1px solid #e9ecef;
        }
        .footer p {
            margin: 5px 0;
            color: #6c757d;
            font-size: 14px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>✅ Payment Confirmed!</h1>
            <p>Your payment has been successfully processed</p>
        </div>
        
        <div class="content">
            <div class="success-message">
                <h2>🎉 Payment Received!</h2>
                <p>Your <?php echo htmlspecialchars($paymentType ?? ''); ?> for order <strong>#VNV341<?php echo htmlspecialchars($orderId ?? ''); ?></strong> has been confirmed and processed successfully.</p>
            </div>
            
            <div class="order-details">
                <h3>📋 Order Details</h3>
                
                <div class="detail-row">
                    <span class="detail-label">Order ID:</span>
                    <span class="detail-value">VNV341<?php echo htmlspecialchars($orderId ?? ''); ?></span>
                </div>
                
                <div class="detail-row">
                    <span class="detail-label">Payment Type:</span>
                    <span class="detail-value"><?php echo htmlspecialchars($paymentType ?? ''); ?></span>
                </div>
                
                <div class="detail-row">
                    <span class="detail-label">Amount Paid:</span>
                    <span class="detail-value" style="font-weight: bold; color: #28a745;">$<?php echo number_format($amount ?? 0, 2); ?></span>
                </div>
                
                <div class="detail-row">
                    <span class="detail-label">Event Date:</span>
                    <span class="detail-value"><?php echo htmlspecialchars($eventDate ?? ''); ?></span>
                </div>
                
                <div class="detail-row">
                    <span class="detail-label">Time:</span>
                    <span class="detail-value"><?php echo htmlspecialchars($eventTime ?? ''); ?></span>
                </div>
                
                <div class="detail-row">
                    <span class="detail-label">Location:</span>
                    <span class="detail-value"><?php echo htmlspecialchars($location ?? ''); ?></span>
                </div>
            </div>
            
            <div class="next-steps">
                <h4>🚀 What's Next?</h4>
                <p><?php echo htmlspecialchars($remainingMessage ?? ''); ?></p>
                <p>You can access your order details anytime using the link below.</p>
            </div>
            
            <div style="text-align: center;">
                <a href="<?php echo htmlspecialchars($orderUrl ?? '#'); ?>" class="cta-button">
                    📋 View Order Details
                </a>
            </div>
            
            <div style="background-color: #d1ecf1; padding: 15px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #17a2b8;">
                <p style="margin: 0; font-size: 14px; color: #0c5460;">
                    <strong>📧 Important:</strong> Keep this email for your records. You can use the link above to access your order anytime.
                </p>
            </div>
        </div>
        
        <div class="footer">
            <p><strong>VNV-Events</strong> - Payment Confirmation</p>
            <p>Thank you for your payment</p>
            <p style="font-size: 12px; color: #adb5bd;">
                This email was sent automatically. Please do not reply to this message.<br>
                Visit us at <a href="https://vnvevents.com/">VNV Events</a>
            </p>
        </div>
    </div>
</body>
</html>
