<?php

use App\Repositories\LeadIntakeRepository;
use App\Services\EmailService;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\Router;

$router = new Router();

function quickRequestField(string $key, int $limit = 1200): string
{
    $value = $_POST[$key] ?? '';
    if (is_array($value)) {
        return '';
    }

    return mb_substr(trim((string)$value), 0, $limit);
}

function quickRequestOwnerId(): int
{
    $owner = (int)($_ENV['VNV_EVENTS_OWNER_ID'] ?? $_ENV['SITE_BUSINESS_USER_ID'] ?? 2);
    return $owner > 0 ? $owner : 2;
}

function quickRequestRedirect(bool $success = false): void
{
    $rawPath = (string)parse_url(quickRequestField('return_path', 240), PHP_URL_PATH);
    $route = trim($rawPath, '/');
    $allowed = [
        '',
        'event-production',
        'nonprofit-partnerships',
        'corporate-events',
        'sponsor-activation-production',
        'vnv-events-productions',
    ];
    if (!in_array($route, $allowed, true)) {
        $route = '';
    }

    $target = LocationUtils::pathFor($route);
    if ($success) {
        $target .= (str_contains($target, '?') ? '&' : '?') . 'quick_submitted=1#quick-request-result';
    }
    LocationUtils::redirectTo($target);
}

$router->post(function () {
    $requestType = strtoupper(quickRequestField('request_type', 30));
    $allowedTypes = ['EVENT', 'PRODUCTION', 'NONPROFIT', 'CORPORATE', 'ACTIVATION'];
    if (!in_array($requestType, $allowedTypes, true)) {
        http_response_code(422);
        exit('Unsupported request.');
    }

    if (quickRequestField('website_confirm', 200) !== '') {
        quickRequestRedirect(true);
    }

    $csrf = quickRequestField('_token', 128);
    $sessionToken = (string)($_SESSION['quick_request_csrf'] ?? '');
    if ($csrf === '' || $sessionToken === '' || !hash_equals($sessionToken, $csrf)) {
        MessageUtil::setMessage('Your form session expired. Please refresh the page and try again.', 'Security', 'warning');
        quickRequestRedirect();
    }

    $now = time();
    $lastSubmit = (int)($_SESSION['quick_request_last_submit'] ?? 0);
    if ($lastSubmit > 0 && ($now - $lastSubmit) < 15) {
        MessageUtil::setMessage('Please wait a few seconds before sending another request.', 'Request', 'warning');
        quickRequestRedirect();
    }

    $name = quickRequestField('contact_name', 191);
    $email = strtolower(quickRequestField('email', 191));
    $phone = quickRequestField('phone', 80);
    $organization = quickRequestField('organization_name', 191);
    $brief = quickRequestField('project_brief', 1800);
    $consent = quickRequestField('consent', 4) === '1';
    $organizationRequired = in_array($requestType, ['NONPROFIT', 'CORPORATE', 'ACTIVATION'], true);

    $errors = [];
    if ($name === '') $errors[] = 'name';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'valid email';
    if ($organizationRequired && $organization === '') $errors[] = 'organization or brand';
    if (mb_strlen($brief) < 10) $errors[] = 'a short project description';
    if (!$consent) $errors[] = 'contact consent';

    if ($errors !== []) {
        MessageUtil::setMessage('Please complete: ' . implode(', ', $errors) . '.', 'Request', 'warning');
        quickRequestRedirect();
    }

    $labels = [
        'EVENT' => 'Event planning request',
        'PRODUCTION' => 'Full event production consultation',
        'NONPROFIT' => 'Nonprofit support application',
        'CORPORATE' => 'Corporate event invitation request',
        'ACTIVATION' => 'Sponsor activation invitation request',
    ];
    $label = $labels[$requestType];
    $source = preg_replace('/[^a-zA-Z0-9_-]/', '', quickRequestField('form_source', 80)) ?: 'public_quick_request';
    $externalId = 'quick-' . strtolower($requestType) . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(3));
    $landingUrl = quickRequestField('landing_url', 500);

    $payload = [
        'request_type' => $requestType,
        'organization_name' => $organization,
        'project_brief' => $brief,
        'form_source' => $source,
        'landing_url' => $landingUrl,
        'utm_source' => quickRequestField('utm_source', 120),
        'utm_medium' => quickRequestField('utm_medium', 120),
        'utm_campaign' => quickRequestField('utm_campaign', 191),
        'utm_term' => quickRequestField('utm_term', 191),
        'utm_content' => quickRequestField('utm_content', 191),
        'gclid' => quickRequestField('gclid', 255),
        'gbraid' => quickRequestField('gbraid', 255),
        'wbraid' => quickRequestField('wbraid', 255),
        'consent' => true,
    ];

    try {
        $leadId = (new LeadIntakeRepository())->upsert(quickRequestOwnerId(), [
            'source' => 'public_quick_request',
            'external_id' => $externalId,
            'channel' => 'website',
            'contact_name' => $name,
            'email' => $email,
            'phone' => $phone !== '' ? $phone : null,
            'service_requested' => $label,
            'guest_count' => null,
            'venue' => $organization !== '' ? $organization : null,
            'event_date' => null,
            'start_time' => null,
            'end_time' => null,
            'setup_minutes' => 60,
            'availability_status' => 'NEEDS_MANUAL_REVIEW',
            'suggested_manager_id' => null,
            'availability_checked_at' => null,
            'status' => 'NEW',
            'payload_json' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);
    } catch (Throwable $e) {
        error_log('Public quick request save error: ' . $e->getMessage());
        MessageUtil::setMessage('We could not save your request. Please try again or call (305) 204-5427.', 'Request', 'error');
        quickRequestRedirect();
    }

    try {
        $emailService = new EmailService(quickRequestOwnerId());
        $safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
        $safeLabel = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
        $safeOrganization = htmlspecialchars($organization, ENT_QUOTES, 'UTF-8');
        $safeBrief = nl2br(htmlspecialchars($brief, ENT_QUOTES, 'UTF-8'));
        $reference = 'VNV-Q' . $leadId;

        $emailService->sendSimpleEmail(
            $email,
            'We received your VNV Events request ' . $reference,
            '<div style="font-family:Arial,sans-serif;max-width:680px;margin:auto;color:#162124"><h2>Thank you, ' . $safeName . '.</h2><p>We received your <strong>' . $safeLabel . '</strong>. Our team will review the brief and contact you for the next appropriate details.</p><p>Reference: <strong>' . $reference . '</strong></p></div>',
            true
        );

        $adminEmail = trim((string)($_ENV['EVENT_REQUEST_EMAIL'] ?? $_ENV['CONTACT_EMAIL'] ?? 'info@vnvevents.com'));
        if (filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
            $emailService->sendSimpleEmail(
                $adminEmail,
                'New ' . $label . ' ' . $reference,
                '<div style="font-family:Arial,sans-serif;max-width:720px"><h2>' . $safeLabel . '</h2><p><strong>Contact:</strong> ' . $safeName . ' &middot; ' . htmlspecialchars($email, ENT_QUOTES, 'UTF-8') . ($phone !== '' ? ' &middot; ' . htmlspecialchars($phone, ENT_QUOTES, 'UTF-8') : '') . '</p>' . ($organization !== '' ? '<p><strong>Organization / brand:</strong> ' . $safeOrganization . '</p>' : '') . '<p><strong>Brief:</strong><br>' . $safeBrief . '</p><p>Open Level 1 &rarr; Lead Intake to review this request.</p></div>',
                true
            );
        }
    } catch (Throwable $e) {
        error_log('Public quick request email error #' . $leadId . ': ' . $e->getMessage());
    }

    $_SESSION['quick_request_last_submit'] = $now;
    $_SESSION['quick_request_csrf'] = bin2hex(random_bytes(24));
    MessageUtil::setMessage('Your request was received. Reference VNV-Q' . $leadId . '.', 'Thank you', 'success');
    quickRequestRedirect(true);
});

$router->run();
