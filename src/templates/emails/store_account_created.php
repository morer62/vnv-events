<?php
/** @var array $templateData */
$clientName = $templateData['clientName'] ?? 'Customer';
$email = $templateData['email'] ?? '';
$tempPassword = $templateData['tempPassword'] ?? '';
$loginUrl = $templateData['loginUrl'] ?? '#';
$companyName = $templateData['companyName'] ?? 'Avomeal';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Your customer account is ready</title>
</head>
<body style="margin:0;padding:0;background:#fff8ef;font-family:Arial,sans-serif;color:#2d241c;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#fff8ef;padding:30px 15px;">
    <tr>
        <td align="center">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:640px;background:#ffffff;border-radius:18px;overflow:hidden;border:1px solid #ecd9bf;">
                <tr>
                    <td style="background:linear-gradient(135deg,#f59e0b,#fb923c);padding:28px 24px;text-align:center;">
                        <h1 style="margin:0;font-size:30px;line-height:1.1;color:#1f1307;font-weight:900;">
                            Your customer account is ready
                        </h1>
                    </td>
                </tr>

                <tr>
                    <td style="padding:30px 24px;">
                        <p style="margin:0 0 14px 0;font-size:16px;line-height:1.7;">
                            Hi <strong><?php echo htmlspecialchars($clientName); ?></strong>,
                        </p>

                        <p style="margin:0 0 14px 0;font-size:16px;line-height:1.7;color:#5f564b;">
                            We created your client account automatically after your order so you can access your information more easily in the future.
                        </p>

                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:20px 0;background:#fff5e6;border:1px solid #efd4a9;border-radius:14px;">
                            <tr>
                                <td style="padding:18px 20px;">
                                    <p style="margin:0 0 8px 0;font-size:15px;color:#5f564b;">
                                        <strong>Email:</strong> <?php echo htmlspecialchars($email); ?>
                                    </p>
                                    <p style="margin:0;font-size:15px;color:#5f564b;">
                                        <strong>Temporary password:</strong> <?php echo htmlspecialchars($tempPassword); ?>
                                    </p>
                                </td>
                            </tr>
                        </table>

                        <p style="margin:0 0 22px 0;font-size:16px;line-height:1.7;color:#5f564b;">
                            We recommend logging in and changing your password as soon as possible.
                        </p>

                        <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 22px 0;">
                            <tr>
                                <td align="center" style="border-radius:999px;background:linear-gradient(135deg,#f59e0b,#fb923c);">
                                    <a href="<?php echo htmlspecialchars($loginUrl); ?>"
                                       style="display:inline-block;padding:15px 26px;font-size:16px;font-weight:900;color:#23160a;text-decoration:none;border-radius:999px;">
                                        Log in now
                                    </a>
                                </td>
                            </tr>
                        </table>

                        <p style="margin:24px 0 0 0;font-size:15px;line-height:1.7;color:#6f6255;">
                            Thank you,<br>
                            <strong><?php echo htmlspecialchars($companyName); ?></strong>
                        </p>
                    </td>
                </tr>

                <tr>
                    <td style="padding:18px 24px;background:#fffaf2;border-top:1px solid #f3dfc3;text-align:center;">
                        <p style="margin:0;font-size:13px;color:#8b7a67;">
                            If you already had an account with this email, you can ignore this message.
                        </p>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
