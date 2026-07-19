<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New Client Request</title>
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
            background-color: #007bff;
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
            color: #007bff;
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
            color: #007bff;
        }
        .client-info {
            background-color: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
            margin: 20px 0;
            border-left: 4px solid #007bff;
        }
        .button-container {
            text-align: center;
            padding: 20px 30px;
            background-color: #f9f9f9;
            border-top: 1px solid #eee;
        }
        .cta-button {
            display: inline-block;
            background-color: #007bff;
            color: #ffffff !important;
            padding: 12px 25px;
            border-radius: 5px;
            text-decoration: none;
            font-weight: bold;
            font-size: 16px;
            border: none;
        }
        .urgent-badge {
            background-color: #dc3545;
            color: white;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: bold;
            margin-left: 10px;
        }
    </style>
</head>
<body>
    <div class="email-container">
        <div class="header">
            <h1>📨 New Client Request!</h1>
        </div>
        <div class="content">
            <p>Dear <?php echo htmlspecialchars($ownerName ?? 'Business Owner'); ?>,</p>
            
            <p>Great news! You have received a new client request for your <?php echo htmlspecialchars($profileType ?? 'service'); ?>.</p>
            
            <div class="client-info">
                <h3>📋 Request Summary</h3>
                <p><strong>Client:</strong> <?php echo htmlspecialchars($clientName ?? 'N/A'); ?></p>
                <p><strong>Event Date:</strong> <?php echo htmlspecialchars($eventDate ?? 'N/A'); ?> at <?php echo htmlspecialchars($eventTime ?? 'N/A'); ?></p>
                <p><strong>Duration:</strong> <?php echo htmlspecialchars($eventDuration ?? 'N/A'); ?></p>
                <?php if (!empty($guests) && $guests !== 'Not specified'): ?>
                <p><strong>Expected Guests:</strong> <?php echo htmlspecialchars($guests); ?></p>
                <?php endif; ?>
                <?php if (!empty($budget) && $budget !== 'Not specified'): ?>
                <p><strong>Budget:</strong> <?php echo htmlspecialchars($budget); ?></p>
                <?php endif; ?>
            </div>
            
            <h2>Client Contact Information:</h2>
            <table class="details-table">
                <tr>
                    <th>Name:</th>
                    <td class="highlight"><?php echo htmlspecialchars($clientName ?? 'N/A'); ?></td>
                </tr>
                <tr>
                    <th>Email:</th>
                    <td><?php echo htmlspecialchars($clientEmail ?? 'N/A'); ?></td>
                </tr>
                <tr>
                    <th>Phone:</th>
                    <td><?php echo htmlspecialchars($clientPhone ?? 'N/A'); ?></td>
                </tr>
                <tr>
                    <th>Event Address:</th>
                    <td><?php echo htmlspecialchars($eventAddress ?? 'N/A'); ?></td>
                </tr>
            </table>
            
            <h2>Event Details:</h2>
            <table class="details-table">
                <tr>
                    <th>Event Type:</th>
                    <td><?php echo htmlspecialchars($eventType ?? 'Not specified'); ?></td>
                </tr>
                <tr>
                    <th>Date & Time:</th>
                    <td><?php echo htmlspecialchars($eventDate ?? 'N/A'); ?> at <?php echo htmlspecialchars($eventTime ?? 'N/A'); ?></td>
                </tr>
                <tr>
                    <th>Duration:</th>
                    <td><?php echo htmlspecialchars($eventDuration ?? 'N/A'); ?></td>
                </tr>
                <?php if (!empty($guests) && $guests !== 'Not specified'): ?>
                <tr>
                    <th>Expected Guests:</th>
                    <td><?php echo htmlspecialchars($guests); ?></td>
                </tr>
                <?php endif; ?>
                <?php if (!empty($budget) && $budget !== 'Not specified'): ?>
                <tr>
                    <th>Budget:</th>
                    <td><?php echo htmlspecialchars($budget); ?></td>
                </tr>
                <?php endif; ?>
            </table>
            
            <?php if (!empty($details) && $details !== 'No additional details provided'): ?>
            <h2>Additional Details:</h2>
            <div style="background-color: #f8f9fa; padding: 15px; border-radius: 5px; border-left: 4px solid #007bff;">
                <?php echo nl2br(htmlspecialchars($details)); ?>
            </div>
            <?php endif; ?>
            
            <p><strong>Next Steps:</strong></p>
            <ul>
                <li>Review the client's requirements and event details</li>
                <li>Contact the client directly using the provided email or phone number</li>
                <li>Discuss availability, pricing, and any specific needs</li>
                <li>Follow up promptly to secure the booking</li>
            </ul>
            
            <p>Don't miss this opportunity! Click the button below to view all your client requests and manage them efficiently.</p>
        </div>
        <div class="button-container">
            <a href="<?php echo htmlspecialchars($requestUrl ?? '#'); ?>" class="cta-button">View Client Requests</a>
        </div>
        <div class="footer">
            <p>&copy; <?php echo date("Y"); ?> VNV-Events. All rights reserved.</p>
            <p>This is an automated email, please do not reply.</p>
            <p>If you have any questions, contact VNV Events at <a href="mailto:info@vnvevents.com">info@vnvevents.com</a>.</p>
        </div>
    </div>
</body>
</html>
