<?php

use App\Services\EmailService;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\Router;

$router = new Router();

function eventRequestRedirectBack(): void
{
    $fallback = LocationUtils::pathFor('');
    $referer = $_SERVER['HTTP_REFERER'] ?? '';
    if ($referer !== '') {
        LocationUtils::redirectTo($referer);
    }
    LocationUtils::redirectTo($fallback);
}

function eventRequestMailTo(): string
{
    $candidates = [
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

function eventRequestVerifyRecaptcha(string $token): bool
{
    $secret = trim((string)($_ENV['GOOGLE_RECAPTCHA_SECRET'] ?? ''));
    if ($secret === '' || $token === '') {
        return false;
    }

    $payload = http_build_query([
        'secret'   => $secret,
        'response' => $token,
        'remoteip' => $_SERVER['REMOTE_ADDR'] ?? '',
    ]);

    $options = [
        'http' => [
            'method'  => 'POST',
            'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
            'content' => $payload,
            'timeout' => 10,
        ],
    ];

    $context = stream_context_create($options);
    $response = @file_get_contents('https://www.google.com/recaptcha/api/siteverify', false, $context);

    if ($response === false) {
        return false;
    }

    $result = json_decode($response, true);
    if (!is_array($result)) {
        return false;
    }

    $success = (bool)($result['success'] ?? false);
    $score   = (float)($result['score'] ?? 0);

    if (!$success) {
        return false;
    }

    return $score >= 0.3;
}

$router->post(function () {
    $eventAddress   = trim((string)($_POST['event_address'] ?? ''));
    $eventDate      = trim((string)($_POST['event_date'] ?? ''));
    $eventTime      = trim((string)($_POST['event_time'] ?? ''));
    $guestCount     = (int)($_POST['guest_count'] ?? 0);
    $fullName       = trim((string)($_POST['full_name'] ?? ''));
    $email          = trim((string)($_POST['email'] ?? ''));
    $phone          = trim((string)($_POST['phone'] ?? ''));
    $details        = trim((string)($_POST['details'] ?? ''));
    $formSource     = trim((string)($_POST['form_source'] ?? 'public_home_modal'));
    $servicesRaw    = (string)($_POST['selected_services'] ?? '[]');
    $recaptchaToken = trim((string)($_POST['g_recaptcha_token'] ?? ''));

    if ($eventAddress === '' || $eventDate === '' || $eventTime === '' || $fullName === '' || $email === '' || $phone === '') {
        MessageUtil::setMessage('Please complete required fields before sending your request.', 'Validation', 'warning');
        eventRequestRedirectBack();
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        MessageUtil::setMessage('Please enter a valid email address.', 'Validation', 'warning');
        eventRequestRedirectBack();
    }

    if (!eventRequestVerifyRecaptcha($recaptchaToken)) {
        MessageUtil::setMessage('Security verification failed. Please try again.', 'Security', 'warning');
        eventRequestRedirectBack();
    }

    $services = json_decode($servicesRaw, true);
    if (!is_array($services)) {
        $services = [];
    }

    $services = array_values(array_filter(array_map(function ($service) {
        return trim((string)$service);
    }, $services), function ($service) {
        return $service !== '';
    }));

    $mailTo = eventRequestMailTo();
    if ($mailTo === '') {
        MessageUtil::setMessage('No destination email configured for event requests. Set EVENT_REQUEST_EMAIL in .env.', 'Configuration', 'error');
        eventRequestRedirectBack();
    }

    $subject = 'New Event Request - ' . $fullName;

    $servicesHtml = $services
        ? '<ul style="margin:8px 0 0;padding-left:18px;"><li>' . implode('</li><li>', array_map('htmlspecialchars', $services)) . '</li></ul>'
        : '<span style="color:#6b7280;">No services selected</span>';

    $body = '
    <div style="font-family:Arial,sans-serif;line-height:1.55;color:#111827;max-width:760px;margin:0 auto;">
        <h2 style="margin:0 0 10px;">New event request received</h2>
        <p style="margin:0 0 16px;color:#4b5563;">A visitor completed the public event request modal.</p>
        <table style="width:100%;border-collapse:collapse;">
            <tr><td style="padding:8px;border:1px solid #e5e7eb;"><strong>Full name</strong></td><td style="padding:8px;border:1px solid #e5e7eb;">' . htmlspecialchars($fullName) . '</td></tr>
            <tr><td style="padding:8px;border:1px solid #e5e7eb;"><strong>Email</strong></td><td style="padding:8px;border:1px solid #e5e7eb;">' . htmlspecialchars($email) . '</td></tr>
            <tr><td style="padding:8px;border:1px solid #e5e7eb;"><strong>Phone</strong></td><td style="padding:8px;border:1px solid #e5e7eb;">' . htmlspecialchars($phone) . '</td></tr>
            <tr><td style="padding:8px;border:1px solid #e5e7eb;"><strong>Event date</strong></td><td style="padding:8px;border:1px solid #e5e7eb;">' . htmlspecialchars($eventDate) . '</td></tr>
            <tr><td style="padding:8px;border:1px solid #e5e7eb;"><strong>Event time</strong></td><td style="padding:8px;border:1px solid #e5e7eb;">' . htmlspecialchars($eventTime) . '</td></tr>
            <tr><td style="padding:8px;border:1px solid #e5e7eb;"><strong>Guest count</strong></td><td style="padding:8px;border:1px solid #e5e7eb;">' . ($guestCount > 0 ? (string)$guestCount : 'Not provided') . '</td></tr>
            <tr><td style="padding:8px;border:1px solid #e5e7eb;"><strong>Event address</strong></td><td style="padding:8px;border:1px solid #e5e7eb;">' . htmlspecialchars($eventAddress) . '</td></tr>
            <tr><td style="padding:8px;border:1px solid #e5e7eb;"><strong>Form source</strong></td><td style="padding:8px;border:1px solid #e5e7eb;">' . htmlspecialchars($formSource) . '</td></tr>
        </table>
        <div style="margin-top:14px;">
            <strong>Selected services</strong>
            ' . $servicesHtml . '
        </div>
        <div style="margin-top:14px;">
            <strong>Details</strong>
            <p style="margin:6px 0 0;color:#374151;">' . nl2br(htmlspecialchars($details !== '' ? $details : 'No additional details provided.')) . '</p>
        </div>
    </div>';

    try {
        $emailService = new EmailService();
        $sent = $emailService->sendSimpleEmail($mailTo, $subject, $body, true);

        if (!$sent) {
            MessageUtil::setMessage('Could not send your request email. Please try again.', 'Email', 'error');
            eventRequestRedirectBack();
        }
    } catch (\Throwable $e) {
        error_log('Public event request email error: ' . $e->getMessage());
        MessageUtil::setMessage('Could not send your request email. Please try again.', 'Email', 'error');
        eventRequestRedirectBack();
    }

    MessageUtil::setMessage('Your request was sent successfully. We will contact you soon.');
    eventRequestRedirectBack();
});

$router->run();