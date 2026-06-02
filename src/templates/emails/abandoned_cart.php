<?php
/** @var array $templateData */
$clientName = $templateData['clientName'] ?? 'there';
$recoveryUrl = $templateData['recoveryUrl'] ?? '#';
$mealsCount = $templateData['mealsCount'] ?? 0;
$total = $templateData['total'] ?? '0.00';
$companyName = $templateData['companyName'] ?? 'Avomeal';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Your meals are waiting</title>
</head>
<body style="margin:0;padding:0;background:#fff8ef;font-family:Arial,sans-serif;color:#2d241c;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#fff8ef;padding:30px 15px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:640px;background:#ffffff;border-radius:18px;overflow:hidden;border:1px solid #ecd9bf;">
                    <tr>
                        <td style="background:linear-gradient(135deg,#f59e0b,#fb923c);padding:28px 24px;text-align:center;">
                            <h1 style="margin:0;font-size:30px;line-height:1.1;color:#1f1307;font-weight:900;">
                                Your meal plan is almost ready 🍽️
                            </h1>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:30px 24px;">
                            <p style="margin:0 0 14px 0;font-size:16px;line-height:1.7;">
                                Hi <strong><?php echo htmlspecialchars($clientName); ?></strong>,
                            </p>

                            <p style="margin:0 0 14px 0;font-size:16px;line-height:1.7;color:#5f564b;">
                                We noticed you left your gourmet order unfinished. Your selected meals are still waiting for you.
                            </p>

                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:20px 0;background:#fff5e6;border:1px solid #efd4a9;border-radius:14px;">
                                <tr>
                                    <td style="padding:18px 20px;">
                                        <p style="margin:0 0 8px 0;font-size:15px;color:#5f564b;">
                                            <strong>Meals selected:</strong> <?php echo htmlspecialchars((string)$mealsCount); ?>
                                        </p>
                                        <p style="margin:0;font-size:15px;color:#5f564b;">
                                            <strong>Estimated total:</strong> $<?php echo htmlspecialchars((string)$total); ?>
                                        </p>
                                    </td>
                                </tr>
                            </table>

                            <p style="margin:0 0 22px 0;font-size:16px;line-height:1.7;color:#5f564b;">
                                Click below to recover your cart and continue checkout.
                            </p>

                            <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 22px 0;">
                                <tr>
                                    <td align="center" style="border-radius:999px;background:linear-gradient(135deg,#f59e0b,#fb923c);">
                                        <a href="<?php echo htmlspecialchars($recoveryUrl); ?>"
                                           style="display:inline-block;padding:15px 26px;font-size:16px;font-weight:900;color:#23160a;text-decoration:none;border-radius:999px;">
                                            Recover my cart
                                        </a>
                                    </td>
                                </tr>
                            </table>

                            <p style="margin:0 0 8px 0;font-size:15px;line-height:1.7;color:#6f6255;">
                                This link will take you back to your saved checkout so you can finish your order quickly.
                            </p>

                            <p style="margin:24px 0 0 0;font-size:15px;line-height:1.7;color:#6f6255;">
                                Thank you,<br>
                                <strong><?php echo htmlspecialchars($companyName); ?></strong>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:18px 24px;background:#fffaf2;border-top:1px solid #f3dfc3;text-align:center;">
                            <p style="margin:0;font-size:13px;color:#8b7a67;">
                                If you already completed your purchase, you can ignore this email.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
