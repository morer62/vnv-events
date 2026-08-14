<?php

use App\Repositories\EventRequestRepository;
use App\Repositories\LeadIntakeRepository;
use App\Services\EmailService;
use App\Services\LoginService;
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

function eventRequestRedirectSuccess(): void
{
    $session = LoginService::getSession();
    if ($session && (int)$session->getLevel() === 5) {
        LocationUtils::redirectInternal('panel/home');
    }

    LocationUtils::redirectTo(LocationUtils::pathFor('sucess-request'));
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

function eventRequestOwnerId(): int
{
    $envOwner = (int)($_ENV['VNV_EVENTS_OWNER_ID'] ?? 0);
    if ($envOwner > 0) {
        return $envOwner;
    }

    $session = LoginService::getSession();
    if ($session && (int)$session->getOwner() > 0) {
        return (int)$session->getOwner();
    }

    return 1;
}

function eventRequestRecaptchaSecret(): string
{
    return trim((string)($_ENV['RECAPTCHA_SECRET_KEY'] ?? ''));
}

function eventRequestRecaptchaMinScore(): float
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

function eventRequestVerifyRecaptcha(string $token, string $remoteIp = ''): array
{
    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    if (str_contains($host, 'localhost') || str_contains($host, '127.0.0.1')) {
        return [
            'success' => true,
            'message' => 'local bypass',
            'score' => null,
            'response' => null,
        ];
    }

    $secret = eventRequestRecaptchaSecret();
    $minScore = eventRequestRecaptchaMinScore();

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

    if ($action !== '' && $action !== 'public_event_request') {
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

$router->post(function () {
    $eventAddress = trim((string)($_POST['event_address'] ?? ''));
    $eventDate = trim((string)($_POST['event_date'] ?? ''));
    $eventTime = trim((string)($_POST['event_time'] ?? ''));
    $guestCount = (int)($_POST['guest_count'] ?? 0);
    $fullName = trim((string)($_POST['full_name'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $phone = trim((string)($_POST['phone'] ?? ''));
    $details = trim((string)($_POST['details'] ?? ''));
    $formSource = trim((string)($_POST['form_source'] ?? 'public_home_modal'));
    $servicesRaw = (string)($_POST['selected_services'] ?? '[]');
    $recaptchaToken = trim((string)($_POST['recaptcha_token'] ?? ''));

    if ($eventAddress === '' || $eventDate === '' || $eventTime === '' || $fullName === '' || $email === '' || $phone === '') {
        MessageUtil::setMessage('Please complete required fields before sending your request.', 'Validation', 'warning');
        eventRequestRedirectBack();
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        MessageUtil::setMessage('Please enter a valid email address.', 'Validation', 'warning');
        eventRequestRedirectBack();
    }

    $recaptchaCheck = eventRequestVerifyRecaptcha(
        $recaptchaToken,
        (string)($_SERVER['REMOTE_ADDR'] ?? '')
    );

    if (!$recaptchaCheck['success']) {
        error_log(
            'Public event request reCAPTCHA failed: '
            . $recaptchaCheck['message']
            . ' | score=' . var_export($recaptchaCheck['score'], true)
            . ' | response=' . json_encode($recaptchaCheck['response'])
        );

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

    $sessionUser = LoginService::getSession();
    $ownerId = eventRequestOwnerId();
    $requestId = null;

    try {
        $requestRepo = new EventRequestRepository();
        $requestId = $requestRepo->createFromPublicForm([
            'id_owner' => $ownerId,
            'id_user' => $sessionUser ? (int)$sessionUser->getId() : null,
            'full_name' => $fullName,
            'email' => $email,
            'phone' => $phone,
            'event_address' => $eventAddress,
            'event_date' => $eventDate,
            'event_time' => $eventTime,
            'guest_count' => $guestCount > 0 ? $guestCount : null,
            'selected_services' => json_encode($services, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'details' => $details,
            'form_source' => $formSource,
            'status' => 'NEW',
            'is_archived' => 0,
        ]);
    } catch (\Throwable $e) {
        error_log('Public event request save error: ' . $e->getMessage());
        MessageUtil::setMessage('Could not save your request. Please try again.', 'Request', 'error');
        eventRequestRedirectBack();
    }

    // Feed the same pre-CRM queue used by ManyChat. This is intentionally
    // best-effort so a temporary scheduling/Lead Intake issue never loses the
    // already-persisted public request.
    try {
        $leadPayload = [
            'event_request_id' => $requestId,
            'source' => $formSource,
            'selected_services' => $services,
            'details' => $details,
            'event_address' => $eventAddress,
            'event_date' => $eventDate,
            'event_time' => $eventTime,
            'guest_count' => $guestCount > 0 ? $guestCount : null,
            'recaptcha_score' => $recaptchaCheck['score'] ?? null,
        ];

        (new LeadIntakeRepository())->upsert($ownerId, [
            'source' => 'public_multistep',
            'external_id' => 'event-request-' . $requestId,
            'channel' => 'website',
            'contact_name' => $fullName,
            'email' => $email,
            'phone' => $phone,
            'service_requested' => $services !== [] ? implode(', ', $services) : null,
            'guest_count' => $guestCount > 0 ? $guestCount : null,
            'venue' => $eventAddress,
            'event_date' => $eventDate,
            'start_time' => $eventTime,
            'end_time' => null,
            'setup_minutes' => 60,
            'availability_status' => 'NEEDS_MANUAL_REVIEW',
            'suggested_manager_id' => null,
            'availability_checked_at' => null,
            'status' => 'NEW',
            'payload_json' => json_encode($leadPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);
    } catch (\Throwable $e) {
        error_log('Public event request Lead Intake sync error for request #' . $requestId . ': ' . $e->getMessage());
    }

    $subject = 'New VNV Event Request #' . $requestId . ' - ' . $fullName;

    $servicesHtml = $services
        ? '<ul style="margin:8px 0 0;padding-left:18px;"><li>' . implode('</li><li>', array_map('htmlspecialchars', $services)) . '</li></ul>'
        : '<span style="color:#6b7280;">No services selected</span>';

    $body = '
    <div style="font-family:Arial,sans-serif;line-height:1.55;color:#111827;max-width:760px;margin:0 auto;">
        <h2 style="margin:0 0 10px;">New event request received</h2>
        <p style="margin:0 0 16px;color:#4b5563;">A visitor/client completed the VNV Events request popup. This request is now saved in the Level 1 dashboard.</p>
        <table style="width:100%;border-collapse:collapse;">
            <tr><td style="padding:8px;border:1px solid #e5e7eb;"><strong>Request ID</strong></td><td style="padding:8px;border:1px solid #e5e7eb;">' . htmlspecialchars((string)$requestId) . '</td></tr>
            <tr><td style="padding:8px;border:1px solid #e5e7eb;"><strong>Full name</strong></td><td style="padding:8px;border:1px solid #e5e7eb;">' . htmlspecialchars($fullName) . '</td></tr>
            <tr><td style="padding:8px;border:1px solid #e5e7eb;"><strong>Email</strong></td><td style="padding:8px;border:1px solid #e5e7eb;">' . htmlspecialchars($email) . '</td></tr>
            <tr><td style="padding:8px;border:1px solid #e5e7eb;"><strong>Phone</strong></td><td style="padding:8px;border:1px solid #e5e7eb;">' . htmlspecialchars($phone) . '</td></tr>
            <tr><td style="padding:8px;border:1px solid #e5e7eb;"><strong>Event date</strong></td><td style="padding:8px;border:1px solid #e5e7eb;">' . htmlspecialchars($eventDate) . '</td></tr>
            <tr><td style="padding:8px;border:1px solid #e5e7eb;"><strong>Event time</strong></td><td style="padding:8px;border:1px solid #e5e7eb;">' . htmlspecialchars($eventTime) . '</td></tr>
            <tr><td style="padding:8px;border:1px solid #e5e7eb;"><strong>Guest count</strong></td><td style="padding:8px;border:1px solid #e5e7eb;">' . ($guestCount > 0 ? (string)$guestCount : 'Not provided') . '</td></tr>
            <tr><td style="padding:8px;border:1px solid #e5e7eb;"><strong>Event address</strong></td><td style="padding:8px;border:1px solid #e5e7eb;">' . htmlspecialchars($eventAddress) . '</td></tr>
            <tr><td style="padding:8px;border:1px solid #e5e7eb;"><strong>Form source</strong></td><td style="padding:8px;border:1px solid #e5e7eb;">' . htmlspecialchars($formSource) . '</td></tr>
            <tr><td style="padding:8px;border:1px solid #e5e7eb;"><strong>reCAPTCHA score</strong></td><td style="padding:8px;border:1px solid #e5e7eb;">' . htmlspecialchars((string)($recaptchaCheck['score'] ?? 'n/a')) . '</td></tr>
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
        $results = $emailService->sendBulkEmail([
            ['email' => 'info@vnvevents.com', 'name' => 'VNV Events'],
            ['email' => 'contact@vnvevents.com', 'name' => 'VNV Events'],
        ], $subject, $body, true);

        if (!in_array(true, $results, true)) {
            MessageUtil::setMessage('Could not send your request email. Please try again.', 'Email', 'error');
            eventRequestRedirectBack();
        }
    } catch (\Throwable $e) {
        error_log('Public event request email error: ' . $e->getMessage());
        MessageUtil::setMessage('Could not send your request email. Please try again.', 'Email', 'error');
        eventRequestRedirectBack();
    }

    MessageUtil::setMessage('Your request was sent successfully.', 'Success', 'success');
    eventRequestRedirectSuccess();
});

$router->run();
