<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Confirmation - Work Hours</title>
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
            background-color: #198754; /* Green header */
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
            color: #198754;
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
            color: #198754;
        }
        .amount-highlight {
            font-size: 24px;
            font-weight: bold;
            color: #198754;
            text-align: center;
            padding: 15px;
            background-color: #f8f9fa;
            border-radius: 5px;
            margin: 20px 0;
        }
    </style>
</head>
<body>
    <div class="email-container">
        <div class="header">
            <h1>💵 Payment Confirmed!</h1>
        </div>
        <div class="content">
            <p>Dear <?php echo htmlspecialchars($employeeName ?? 'Employee'); ?>,</p>
            <p>We are pleased to confirm that your work hours payment has been processed successfully.</p>
            
            <div class="amount-highlight">
                $<?php echo number_format($paymentAmount ?? 0, 2); ?>
            </div>
            
            <h2>Payment Details:</h2>
            <table class="details-table">
                <tr>
                    <th>Payment Date:</th>
                    <td><?php echo htmlspecialchars($paymentDate ?? date('F j, Y g:i A')); ?></td>
                </tr>
                <tr>
                    <th>Payment Method:</th>
                    <td><?php echo htmlspecialchars($paymentMethod ?? 'Manual Payment'); ?></td>
                </tr>
                <tr>
                    <th>Sessions Paid:</th>
                    <td><?php echo htmlspecialchars($sessionsCount ?? 0); ?> work session(s)</td>
                </tr>
                <tr>
                    <th>Total Amount:</th>
                    <td class="highlight">$<?php echo number_format($paymentAmount ?? 0, 2); ?></td>
                </tr>
            </table>

            <?php if (!empty($additionalInfo) && $additionalInfo !== 'No additional message'): ?>
            <h2>Additional Information:</h2>
            <p style="background-color: #f8f9fa; padding: 15px; border-radius: 5px; border-left: 4px solid #198754;">
                <?php echo nl2br(htmlspecialchars($additionalInfo)); ?>
            </p>
            <?php endif; ?>

            <p>You can view your complete payroll history and payment details in your dashboard.</p>
        </div>
        <div class="footer">
            <p>&copy; <?php echo date("Y"); ?> VNV-Events. All rights reserved.</p>
            <p>This is an automated email, please do not reply.</p>
            <p>Visit us at <a href="https://vnvevents.com/">VNV Events</a></p>
        </div>
    </div>
</body>
</html>
