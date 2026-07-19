<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Membership Expiry Notification</title>
    <style>
        body {
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            margin: 0;
            padding: 0;
            background-color: #f4f4f4;
            color: #333;
        }
        .email-container {
            max-width: 600px;
            margin: 20px auto;
            background-color: #ffffff;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
        }
        .header {
            background-color: <?php echo $isExpired ? '#dc3545' : '#ffc107'; ?>; /* Red for expired, Yellow for expiring */
            color: #ffffff;
            padding: 20px;
            text-align: center;
        }
        .header h1 {
            margin: 0;
            font-size: 24px;
        }
        .content {
            padding: 20px 30px;
            line-height: 1.6;
            color: #555;
        }
        .content h2 {
            color: <?php echo $isExpired ? '#dc3545' : '#ffc107'; ?>;
            font-size: 20px;
            margin-top: 0;
        }
        .footer {
            background-color: #f0f0f0;
            color: #888;
            text-align: center;
            padding: 15px;
            font-size: 12px;
        }
        .details-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        .details-table th, .details-table td {
            border: 1px solid #eee;
            padding: 10px;
            text-align: left;
        }
        .details-table th {
            background-color: #f8f8f8;
            font-weight: normal;
            width: 30%;
        }
        .highlight {
            font-weight: bold;
            color: <?php echo $isExpired ? '#dc3545' : '#ffc107'; ?>;
        }
        .alert-box {
            background-color: <?php echo $isExpired ? '#f8d7da' : '#fff3cd'; ?>;
            border: 1px solid <?php echo $isExpired ? '#f5c6cb' : '#ffeaa7'; ?>;
            color: <?php echo $isExpired ? '#721c24' : '#856404'; ?>;
            padding: 15px;
            border-radius: 5px;
            margin: 20px 0;
        }
        .button-container {
            text-align: center;
            padding: 20px 30px;
            background-color: #f9f9f9;
            border-top: 1px solid #eee;
        }
        .cta-button {
            display: inline-block;
            background-color: <?php echo $isExpired ? '#dc3545' : '#ffc107'; ?>;
            color: #ffffff !important;
            padding: 12px 25px;
            border-radius: 5px;
            text-decoration: none;
            font-weight: bold;
            font-size: 16px;
            border: none;
        }
    </style>
</head>
<body>
    <div class="email-container">
        <div class="header">
            <h1><?php echo $isExpired ? '⚠️ Membership Expired' : '⏰ Membership Expiring Soon'; ?></h1>
        </div>
        <div class="content">
            <p>Dear <?php echo htmlspecialchars($userName ?? 'Valued Customer'); ?>,</p>
            
            <?php if ($isExpired): ?>
                <p>We hope this email finds you well. We wanted to inform you that your VNV-Events membership has expired and requires immediate attention.</p>
            <?php else: ?>
                <p>We hope this email finds you well. We wanted to remind you that your VNV-Events membership will expire soon.</p>
            <?php endif; ?>
            
            <div class="alert-box">
                <strong><?php echo $isExpired ? 'Action Required:' : 'Important Notice:'; ?></strong>
                <?php if ($isExpired): ?>
                    Your membership expired on <?php echo htmlspecialchars($expiryDate ?? 'N/A'); ?>. To continue enjoying all VNV-Events features, please renew your membership as soon as possible.
                <?php else: ?>
                    Your membership will expire on <?php echo htmlspecialchars($expiryDate ?? 'N/A'); ?>. To avoid any service interruption, we recommend renewing your membership now.
                <?php endif; ?>
            </div>
            
            <h2>Membership Details:</h2>
            <table class="details-table">
                <tr>
                    <th>Status:</th>
                    <td class="highlight"><?php echo $isExpired ? 'Expired' : 'Expiring Soon'; ?></td>
                </tr>
                <tr>
                    <th>Expiry Date:</th>
                    <td><?php echo htmlspecialchars($expiryDate ?? 'N/A'); ?></td>
                </tr>
                <tr>
                    <th>Account Type:</th>
                    <td><?php echo htmlspecialchars($membershipType ?? 'Standard'); ?></td>
                </tr>
            </table>

            <?php if ($isExpired): ?>
                <p><strong>What happens next?</strong></p>
                <ul>
                    <li>Some features may be limited or unavailable</li>
                    <li>You'll need to renew to restore full access</li>
                    <li>Your data and settings are safely preserved</li>
                </ul>
            <?php else: ?>
                <p><strong>Why renew now?</strong></p>
                <ul>
                    <li>Avoid service interruption</li>
                    <li>Maintain uninterrupted access to all features</li>
                    <li>Keep your business running smoothly</li>
                </ul>
            <?php endif; ?>

            <p>Renewing your membership is quick and easy. Simply click the button below to access your account and complete the renewal process.</p>
        </div>
        <div class="button-container">
            <a href="<?php echo htmlspecialchars($renewalUrl ?? '#'); ?>" class="cta-button">
                <?php echo $isExpired ? 'Renew Membership Now' : 'Renew Membership'; ?>
            </a>
        </div>
        <div class="footer">
            <p>&copy; <?php echo date("Y"); ?> VNV-Events. All rights reserved.</p>
            <p>This is an automated email, please do not reply.</p>
            <p>If you have any questions, please contact VNV Events at <a href="mailto:info@vnvevents.com">info@vnvevents.com</a>.</p>
        </div>
    </div>
</body>
</html>
