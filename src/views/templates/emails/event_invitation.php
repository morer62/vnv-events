<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>You're Invited!</title>
    <style>
        body {
            font-family: 'Arial', 'Helvetica', sans-serif;
            background-color: #f4f4f4;
            margin: 0;
            padding: 0;
            -webkit-font-smoothing: antialiased;
        }
        .email-container {
            max-width: 600px;
            margin: 40px auto;
            background-color: #ffffff;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        .header {
            background: linear-gradient(135deg, <?= htmlspecialchars($event->primary_color) ?>, <?= htmlspecialchars($event->secondary_color) ?>);
            padding: 50px 30px;
            text-align: center;
            color: #ffffff;
        }
        .header h1 {
            margin: 0;
            font-size: 36px;
            font-weight: bold;
        }
        .header p {
            font-size: 22px;
            margin-top: 15px;
            font-weight: 300;
        }
        .content {
            padding: 40px 35px;
        }
        .greeting {
            font-size: 18px;
            color: #333;
            margin-bottom: 20px;
        }
        .message {
            font-size: 16px;
            line-height: 1.6;
            color: #555;
            margin-bottom: 30px;
        }
        .event-details {
            margin: 30px 0;
            padding: 25px;
            background-color: #f9f9f9;
            border-left: 5px solid <?= htmlspecialchars($event->primary_color) ?>;
            border-radius: 5px;
        }
        .event-details .detail-row {
            margin: 15px 0;
            font-size: 16px;
            color: #333;
        }
        .event-details .detail-row strong {
            color: <?= htmlspecialchars($event->primary_color) ?>;
            font-weight: 600;
            display: inline-block;
            width: 90px;
        }
        .cta-button {
            display: block;
            text-align: center;
            margin: 35px 0;
        }
        .cta-button a {
            display: inline-block;
            padding: 18px 50px;
            background-color: <?= htmlspecialchars($event->primary_color) ?>;
            color: #ffffff !important;
            text-decoration: none;
            border-radius: 8px;
            font-weight: bold;
            font-size: 18px;
            transition: all 0.3s ease;
        }
        .rsvp-notice {
            text-align: center;
            margin-top: 25px;
            font-size: 14px;
            color: #666;
            font-style: italic;
        }
        .footer {
            background-color: #f9f9f9;
            padding: 25px;
            text-align: center;
            font-size: 13px;
            color: #999;
            border-top: 1px solid #e0e0e0;
        }
        .footer a {
            color: <?= htmlspecialchars($event->primary_color) ?>;
            text-decoration: none;
        }
        @media only screen and (max-width: 600px) {
            .email-container {
                margin: 20px 10px;
            }
            .header h1 {
                font-size: 28px;
            }
            .header p {
                font-size: 18px;
            }
            .content {
                padding: 30px 20px;
            }
            .cta-button a {
                padding: 15px 35px;
                font-size: 16px;
            }
        }
    </style>
</head>
<body>
    <div class="email-container">
        <!-- Header -->
        <div class="header">
            <h1>🎉 You're Invited!</h1>
            <p><?= htmlspecialchars($event->event_name) ?></p>
        </div>

        <!-- Content -->
        <div class="content">
            <div class="greeting">
                Dear <?= htmlspecialchars($guest->first_name) ?>,
            </div>

            <?php if (!empty($event->custom_message)): ?>
            <div class="message">
                <?= nl2br(htmlspecialchars($event->custom_message)) ?>
            </div>
            <?php endif; ?>

            <!-- Event Details -->
            <div class="event-details">
                <div class="detail-row">
                    <strong>📅 Date:</strong> <?= htmlspecialchars($eventDate) ?>
                </div>
                <div class="detail-row">
                    <strong>🕐 Time:</strong> <?= htmlspecialchars($eventTime) ?>
                </div>
                <?php if (!empty($event->venue_name)): ?>
                <div class="detail-row">
                    <strong>📍 Venue:</strong> <?= htmlspecialchars($event->venue_name) ?>
                </div>
                <?php endif; ?>
                <?php if (!empty($event->venue_address)): ?>
                <div class="detail-row">
                    <strong>&nbsp;&nbsp;&nbsp;&nbsp;Address:</strong> <?= htmlspecialchars($event->venue_address) ?>
                </div>
                <?php endif; ?>
                <?php if (!empty($event->dress_code)): ?>
                <div class="detail-row">
                    <strong>👔 Dress Code:</strong> <?= htmlspecialchars($event->dress_code) ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- CTA Button -->
            <div class="cta-button">
                <a href="<?= htmlspecialchars($invitationUrl) ?>">RSVP Now</a>
            </div>

            <div class="rsvp-notice">
                Please RSVP by <?= htmlspecialchars($rsvpDeadline) ?>
            </div>
        </div>

        <!-- Footer -->
        <div class="footer">
            <p>
                This invitation was sent to you by <?= htmlspecialchars($event->event_name) ?>
                <br>
                <strong>VNV Events</strong>
            </p>
            <p>
                <a href="<?= htmlspecialchars($invitationUrl) ?>">View Invitation Online</a>
            </p>
        </div>
    </div>
</body>
</html>

