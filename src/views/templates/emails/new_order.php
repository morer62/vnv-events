<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New Order Created</title>
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
            background: linear-gradient(135deg, #007bff 0%, #0056b3 100%);
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
            background-color: #d1ecf1;
            border: 1px solid #bee5eb;
            color: #0c5460;
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
            background-color: #fff3cd;
            padding: 20px;
            border-radius: 8px;
            margin: 20px 0;
        }
        .next-steps h4 {
            color: #856404;
            margin-top: 0;
        }
        .services-list {
            background-color: #e8f5e8;
            padding: 20px;
            border-radius: 8px;
            margin: 20px 0;
        }
        .services-list h4 {
            color: #155724;
            margin-top: 0;
        }
        .service-item {
            padding: 8px 0;
            border-bottom: 1px solid #c3e6cb;
        }
        .service-item:last-child {
            border-bottom: none;
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
            <h1>📝 New Order Created!</h1>
            <p>A new order has been created for your event</p>
        </div>
        
        <div class="content">
            <div class="success-message">
                <h2>🎉 Order Confirmed!</h2>
                <?php if (isset($isSubOrder) && $isSubOrder): ?>
                    <p>Your new sub-order <strong>#<?php echo htmlspecialchars($subOrderId ?? ''); ?></strong> for order <strong>#VNV341<?php echo htmlspecialchars($orderId ?? ''); ?></strong> has been created and is ready for your signature.</p>
                <?php else: ?>
                    <p>Your new order <strong>#VNV341<?php echo htmlspecialchars($orderId ?? ''); ?></strong> has been created and is ready for your signature.</p>
                <?php endif; ?>
            </div>
            
            <div class="order-details">
                <h3>📋 Order Details</h3>
                
                <div class="detail-row">
                    <span class="detail-label">Order ID:</span>
                    <span class="detail-value">VNV341<?php echo htmlspecialchars($orderId ?? ''); ?></span>
                </div>
                
                <?php if (isset($isSubOrder) && $isSubOrder): ?>
                <div class="detail-row">
                    <span class="detail-label">Sub-Order ID:</span>
                    <span class="detail-value">Sub-<?php echo htmlspecialchars($subOrderId ?? ''); ?></span>
                </div>
                <?php endif; ?>
                
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
                
                <div class="detail-row">
                    <span class="detail-label">Total Amount:</span>
                    <span class="detail-value" style="font-weight: bold; color: #28a745;">$<?php echo number_format($totalAmount ?? 0, 2); ?></span>
                </div>
                
                <div class="detail-row">
                    <span class="detail-label">Status:</span>
                    <span class="detail-value" style="color: #ffc107; font-weight: bold;">📝 Awaiting Signature</span>
                </div>
            </div>
            
            <?php if (!empty($services)): ?>
            <div class="services-list">
                <h4>🛠️ Services Included</h4>
                <?php foreach ($services as $service): ?>
                <div class="service-item">
                    <strong><?php echo htmlspecialchars($service['name']); ?></strong>
                    <br>
                    <small>Quantity: <?php echo htmlspecialchars($service['quantity']); ?> | 
                    Unit Price: $<?php echo number_format($service['unit_price'], 2); ?> | 
                    Subtotal: $<?php echo number_format($service['subtotal'], 2); ?></small>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            
            <div class="next-steps">
                <h4>🚀 Next Steps</h4>
                <p>To proceed with your order, you need to:</p>
                <ol>
                    <li><strong>Review the contract</strong> - Check all details and terms</li>
                    <li><strong>Sign the contract</strong> - Provide your digital signature</li>
                    <li><strong>Complete payment</strong> - Pay according to the selected payment plan</li>
                </ol>
            </div>
            
            <div style="text-align: center;">
                <a href="<?php echo htmlspecialchars($orderUrl ?? '#'); ?>" class="cta-button">
                    ✍️ Sign Contract & Pay
                </a>
            </div>
            
            <div style="background-color: #d1ecf1; padding: 15px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #17a2b8;">
                <p style="margin: 0; font-size: 14px; color: #0c5460;">
                    <strong>📧 Important:</strong> Keep this email for your records. You can use the link above to access your order anytime.
                </p>
            </div>
        </div>
        
        <div class="footer">
            <p><strong>VNV-Events</strong> - New Order Confirmation</p>
            <p>Thank you for choosing our services</p>
            <p style="font-size: 12px; color: #adb5bd;">
                This email was sent automatically. Please do not reply to this message.<br>
                Visit us at <a href="https://vnvevents.com/">VNV Events</a>
            </p>
        </div>
    </div>
</body>
</html>

