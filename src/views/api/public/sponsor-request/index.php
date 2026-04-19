<?php

use App\Services\EmailService;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\Router;

$router = new Router();

function sponsorRequestRedirectBack(): void
{
    $fallback = LocationUtils::pathFor('');
    $referer = $_SERVER['HTTP_REFERER'] ?? '';
    if ($referer !== '') {
        LocationUtils::redirectTo($referer);
    }
    LocationUtils::redirectTo($fallback);
}

function sponsorRequestRedirectSuccess(): void
{
    LocationUtils::redirectTo(LocationUtils::pathFor('sucess-request'));
}

function sponsorRequestMailTo(): string
{
    $candidates = [
        trim((string)($_ENV['SPONSOR_REQUEST_EMAIL'] ?? '')),
        trim((string)($_ENV['EVENT_REQUEST_EMAIL'] ?? '')),
        trim((string)($_ENV['CONTACT_EMAIL'] ?? '')),
        trim((string)($_ENV['MAIL_FROM_EMAIL'] ?? '')),
        trim((string)($_ENV['STRIPE_SUPPORT_EMAIL'] ?? '')),
        trim((string)($_ENV['SQUARE_SUPPORT_EMAIL'] ?? '')),
    ];

    foreach ($candidates as $email) {
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $email;
        }
    }

    return '';
}

function sponsorRequestRecaptchaSecret(): string
{
    return trim((string)($_ENV['RECAPTCHA_SECRET_KEY'] ?? ''));
}

function sponsorRequestRecaptchaMinScore(): float
{
    $value = $_ENV['RECAPTCHA_MIN_SCORE'] ?? 0.5;
    $score = is_numeric($value) ? (float)$value : 0.5;

    if ($score < 0) {
        return 0.0;
    }

    if ($score > 1) {
        return 1.0;
    }

    return $score;
}

function sponsorRequestVerifyRecaptcha(string $token, string $remoteIp = ''): array
{
    $secret = sponsorRequestRecaptchaSecret();
    $minScore = sponsorRequestRecaptchaMinScore();

    if ($secret === '') {
        return [
            'success' => false,
            'message' => 'Missing RECAPTCHA_SECRET_KEY.',
            'score' => null,
            'response' => null,
        ];
    }

    if ($token === '') {
        return [
            'success' => false,
            'message' => 'Missing recaptcha token.',
            'score' => null,
            'response' => null,
        ];
    }

    $payload = [
        'secret'   => $secret,
        'response' => $token,
    ];

    if ($remoteIp !== '') {
        $payload['remoteip'] = $remoteIp;
    }

    $postData = http_build_query($payload);

    $options = [
        'http' => [
            'method'  => 'POST',
            'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
            'content' => $postData,
            'timeout' => 10,
        ],
    ];

    $context = stream_context_create($options);
    $result = @file_get_contents('https://www.google.com/recaptcha/api/siteverify', false, $context);

    if ($result === false) {
        return [
            'success' => false,
            'message' => 'Could not contact Google reCAPTCHA.',
            'score' => null,
            'response' => null,
        ];
    }

    $decoded = json_decode($result, true);

    if (!is_array($decoded)) {
        return [
            'success' => false,
            'message' => 'Invalid reCAPTCHA response.',
            'score' => null,
            'response' => $result,
        ];
    }

    $success = (bool)($decoded['success'] ?? false);
    $score = isset($decoded['score']) && is_numeric($decoded['score']) ? (float)$decoded['score'] : null;
    $action = trim((string)($decoded['action'] ?? ''));

    if (!$success) {
        return [
            'success' => false,
            'message' => 'Google rejected the reCAPTCHA token.',
            'score' => $score,
            'response' => $decoded,
        ];
    }

    if ($action !== '' && $action !== 'public_sponsor_request') {
        return [
            'success' => false,
            'message' => 'Invalid reCAPTCHA action.',
            'score' => $score,
            'response' => $decoded,
        ];
    }

    if ($score !== null && $score < $minScore) {
        return [
            'success' => false,
            'message' => 'Low reCAPTCHA score.',
            'score' => $score,
            'response' => $decoded,
        ];
    }

    return [
        'success' => true,
        'message' => 'ok',
        'score' => $score,
        'response' => $decoded,
    ];
}

function sponsorRequestDecodeJsonArray(string $raw): array
{
    $items = json_decode($raw, true);

    if (!is_array($items)) {
        return [];
    }

    $items = array_map(function ($item) {
        return trim((string)$item);
    }, $items);

    $items = array_filter($items, function ($item) {
        return $item !== '';
    });

    return array_values(array_unique($items));
}

$router->post(function () {
    $company = trim((string)($_POST['company'] ?? ''));
    $role = trim((string)($_POST['role'] ?? ''));
    $fullName = trim((string)($_POST['full_name'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $phone = trim((string)($_POST['phone'] ?? ''));
    $details = trim((string)($_POST['details'] ?? ''));
    $formSource = trim((string)($_POST['form_source'] ?? 'public_sponsor_modal'));
    $selectedEventsRaw = (string)($_POST['selected_events'] ?? '[]');
    $selectedPackagesRaw = (string)($_POST['selected_packages'] ?? '[]');
    $recaptchaToken = trim((string)($_POST['recaptcha_token'] ?? ''));

    if (
        $company === '' ||
        $role === '' ||
        $fullName === '' ||
        $email === '' ||
        $phone === ''
    ) {
        MessageUtil::setMessage('Please complete required fields before sending your sponsor request.', 'Validation', 'warning');
        sponsorRequestRedirectBack();
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        MessageUtil::setMessage('Please enter a valid email address.', 'Validation', 'warning');
        sponsorRequestRedirectBack();
    }

    $selectedEvents = sponsorRequestDecodeJsonArray($selectedEventsRaw);
    $selectedPackages = sponsorRequestDecodeJsonArray($selectedPackagesRaw);

    if (empty($selectedEvents)) {
        MessageUtil::setMessage('Please select at least one event.', 'Validation', 'warning');
        sponsorRequestRedirectBack();
    }

    if (empty($selectedPackages)) {
        MessageUtil::setMessage('Please select at least one sponsor package.', 'Validation', 'warning');
        sponsorRequestRedirectBack();
    }

    $recaptchaCheck = sponsorRequestVerifyRecaptcha(
        $recaptchaToken,
        (string)($_SERVER['REMOTE_ADDR'] ?? '')
    );

    if (!$recaptchaCheck['success']) {
        error_log(
            'Public sponsor request reCAPTCHA failed: '
            . $recaptchaCheck['message']
            . ' | score=' . var_export($recaptchaCheck['score'], true)
            . ' | response=' . json_encode($recaptchaCheck['response'])
        );

        MessageUtil::setMessage('Security verification failed. Please try again.', 'Security', 'warning');
        sponsorRequestRedirectBack();
    }

    $mailTo = sponsorRequestMailTo();
    if ($mailTo === '') {
        MessageUtil::setMessage('No destination email configured for sponsor requests. Set SPONSOR_REQUEST_EMAIL in .env.', 'Configuration', 'error');
        sponsorRequestRedirectBack();
    }

    $subject = 'New Sponsor Request - ' . $company . ' - ' . $fullName;

    $eventsHtml = '<ul style="margin:8px 0 0;padding-left:18px;"><li>' .
        implode('</li><li>', array_map('htmlspecialchars', $selectedEvents)) .
        '</li></ul>';

    $packagesHtml = '<ul style="margin:8px 0 0;padding-left:18px;"><li>' .
        implode('</li><li>', array_map('htmlspecialchars', $selectedPackages)) .
        '</li></ul>';

    $body = '
    <div style="font-family:Arial,sans-serif;line-height:1.55;color:#111827;max-width:760px;margin:0 auto;">
        <h2 style="margin:0 0 10px;">New sponsor request received</h2>
        <p style="margin:0 0 16px;color:#4b5563;">A visitor completed the public sponsor modal.</p>

        <table style="width:100%;border-collapse:collapse;">
            <tr>
                <td style="padding:8px;border:1px solid #e5e7eb;"><strong>Company</strong></td>
                <td style="padding:8px;border:1px solid #e5e7eb;">' . htmlspecialchars($company) . '</td>
            </tr>
            <tr>
                <td style="padding:8px;border:1px solid #e5e7eb;"><strong>Role / Title</strong></td>
                <td style="padding:8px;border:1px solid #e5e7eb;">' . htmlspecialchars($role) . '</td>
            </tr>
            <tr>
                <td style="padding:8px;border:1px solid #e5e7eb;"><strong>Full name</strong></td>
                <td style="padding:8px;border:1px solid #e5e7eb;">' . htmlspecialchars($fullName) . '</td>
            </tr>
            <tr>
                <td style="padding:8px;border:1px solid #e5e7eb;"><strong>Email</strong></td>
                <td style="padding:8px;border:1px solid #e5e7eb;">' . htmlspecialchars($email) . '</td>
            </tr>
            <tr>
                <td style="padding:8px;border:1px solid #e5e7eb;"><strong>Phone</strong></td>
                <td style="padding:8px;border:1px solid #e5e7eb;">' . htmlspecialchars($phone) . '</td>
            </tr>
            <tr>
                <td style="padding:8px;border:1px solid #e5e7eb;"><strong>Form source</strong></td>
                <td style="padding:8px;border:1px solid #e5e7eb;">' . htmlspecialchars($formSource) . '</td>
            </tr>
            <tr>
                <td style="padding:8px;border:1px solid #e5e7eb;"><strong>reCAPTCHA score</strong></td>
                <td style="padding:8px;border:1px solid #e5e7eb;">' . htmlspecialchars((string)($recaptchaCheck['score'] ?? 'n/a')) . '</td>
            </tr>
        </table>

        <div style="margin-top:14px;">
            <strong>Selected events</strong>
            ' . $eventsHtml . '
        </div>

        <div style="margin-top:14px;">
            <strong>Selected sponsor packages</strong>
            ' . $packagesHtml . '
        </div>

        <div style="margin-top:14px;">
            <strong>Observations / notes</strong>
            <p style="margin:6px 0 0;color:#374151;">' . nl2br(htmlspecialchars($details !== '' ? $details : 'No additional details provided.')) . '</p>
        </div>
    </div>';

    try {
        $emailService = new EmailService();
        $sent = $emailService->sendSimpleEmail($mailTo, $subject, $body, true);

        if (!$sent) {
            MessageUtil::setMessage('Could not send your sponsor request email. Please try again.', 'Email', 'error');
            sponsorRequestRedirectBack();
        }
    } catch (\Throwable $e) {
        error_log('Public sponsor request email error: ' . $e->getMessage());
        MessageUtil::setMessage('Could not send your sponsor request email. Please try again.', 'Email', 'error');
        sponsorRequestRedirectBack();
    }

    MessageUtil::setMessage('Your sponsor request was sent successfully.', 'Success', 'success');
    sponsorRequestRedirectSuccess();
});

$router->run();