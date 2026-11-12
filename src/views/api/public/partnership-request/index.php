<?php

use App\Repositories\CommercialPartnershipRepository;
use App\Repositories\LeadIntakeRepository;
use App\Services\EmailService;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\Router;
use App\Utils\SiteContext;

$router = new Router();

function partnershipField(string $key, int $limit = 1000): string
{
    $value = $_POST[$key] ?? '';
    if (is_array($value)) {
        return '';
    }
    return mb_substr(trim((string)$value), 0, $limit);
}

function partnershipRedirect(string $program, bool $success = false): void
{
    $route = match ($program) {
        'ACTIVATION' => 'sponsor-activation-production',
        'ORIGINAL' => 'vnv-events-productions',
        default => 'nonprofit-partnerships',
    };
    LocationUtils::redirectInternal($route . ($success ? '?submitted=1' : '#application'));
}

function partnershipOwnerId(): int
{
    $owner = (int)($_ENV['VNV_EVENTS_OWNER_ID'] ?? $_ENV['SITE_BUSINESS_USER_ID'] ?? 2);
    return $owner > 0 ? $owner : 2;
}

function partnershipArray(string $key, int $limit = 20): array
{
    $values = $_POST[$key] ?? [];
    if (!is_array($values)) {
        $values = [$values];
    }
    return array_slice(array_values(array_filter(array_map(
        static fn ($value) => mb_substr(trim((string)$value), 0, 150),
        $values
    ))), 0, $limit);
}

$router->post(function () {
    $program = strtoupper(partnershipField('program', 30));
    if (!in_array($program, ['NONPROFIT', 'ACTIVATION', 'ORIGINAL'], true)) {
        http_response_code(422);
        exit('Unsupported request.');
    }

    if (partnershipField('website_confirm', 200) !== '') {
        partnershipRedirect($program, true);
    }

    $csrf = partnershipField('_token', 128);
    $sessionToken = (string)($_SESSION['partnership_csrf'] ?? '');
    if ($csrf === '' || $sessionToken === '' || !hash_equals($sessionToken, $csrf)) {
        MessageUtil::setMessage('Your form session expired. Please refresh the page and try again.', 'Security', 'warning');
        partnershipRedirect($program);
    }

    $now = time();
    $lastSubmit = (int)($_SESSION['partnership_last_submit'] ?? 0);
    if ($lastSubmit > 0 && ($now - $lastSubmit) < 15) {
        MessageUtil::setMessage('Please wait a few seconds before sending another request.', 'Request', 'warning');
        partnershipRedirect($program);
    }

    $contactName = partnershipField('contact_name', 191);
    $email = strtolower(partnershipField('email', 191));
    $phone = partnershipField('phone', 80);
    $organizationName = $program === 'NONPROFIT'
        ? partnershipField('organization_name', 191)
        : ($program === 'ACTIVATION' ? partnershipField('brand_name', 191) : '');
    $consent = isset($_POST['consent']) && (string)$_POST['consent'] === '1';

    $errors = [];
    if ($contactName === '') $errors[] = 'contact name';
    if ($program !== 'ORIGINAL' && $organizationName === '') $errors[] = $program === 'NONPROFIT' ? 'organization name' : 'brand name';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'valid email';
    if ($phone === '') $errors[] = 'phone';
    if (!$consent) $errors[] = 'consent';
    if ($program === 'NONPROFIT' && partnershipField('event_name', 220) === '') $errors[] = 'event name';
    if ($program === 'ACTIVATION' && partnershipField('event_organizer', 220) === '') $errors[] = 'event or organizer';
    if ($program === 'ORIGINAL' && !in_array(partnershipField('event_name', 220), ['Retro Glam', 'Mommy and Daddy Break'], true)) $errors[] = 'experience';

    if ($errors) {
        MessageUtil::setMessage('Please complete: ' . implode(', ', $errors) . '.', 'Request', 'warning');
        partnershipRedirect($program);
    }

    $eventDate = partnershipField('event_date', 10);
    if ($eventDate !== '') {
        $parsed = DateTimeImmutable::createFromFormat('Y-m-d', $eventDate);
        if (!$parsed || $parsed->format('Y-m-d') !== $eventDate) {
            MessageUtil::setMessage('Please provide a valid event date.', 'Request', 'warning');
            partnershipRedirect($program);
        }
    }

    $data = [
        'organization_name' => partnershipField('organization_name', 191),
        'brand_name' => partnershipField('brand_name', 191),
        'website' => partnershipField('organization_website', 255),
        'legal_status' => partnershipField('legal_status', 120),
        'ein' => partnershipField('ein', 40),
        'contact_name' => $contactName,
        'contact_role' => partnershipField('contact_role', 150),
        'email' => $email,
        'phone' => $phone,
        'event_name' => partnershipField('event_name', 220),
        'event_organizer' => partnershipField('event_organizer', 220),
        'event_date' => $eventDate,
        'venue' => partnershipField('venue', 255),
        'city' => partnershipField('city', 120),
        'event_format' => partnershipField('event_format', 120),
        'guest_count' => max(0, (int)partnershipField('guest_count', 8)),
        'budget_range' => partnershipField('budget_range', 120),
        'desired_outcomes' => partnershipField('desired_outcomes', 2000),
        'existing_sponsors' => partnershipField('existing_sponsors', 1200),
        'requested_services' => partnershipArray('requested_services'),
        'timeframe' => partnershipField('timeframe', 120),
        'sponsor_status' => partnershipField('sponsor_status', 120),
        'activation_type' => partnershipField('activation_type', 150),
        'dimensions' => partnershipField('dimensions', 150),
        'audience' => partnershipField('audience', 500),
        'approval_requirements' => partnershipField('approval_requirements', 1000),
        'artwork_owner' => partnershipField('artwork_owner', 150),
        'electricity' => partnershipField('electricity', 120),
        'staffing' => partnershipField('staffing', 500),
        'deliverables' => partnershipArray('deliverables'),
        'notes' => partnershipField('notes', 2500),
        'consent_text' => 'I agree that VNV Events may contact me about this request. Submission does not guarantee approval, support, sponsorship results or availability.',
        'source' => match ($program) {
            'NONPROFIT' => 'nonprofit_landing',
            'ACTIVATION' => 'activation_landing',
            default => 'original_priority_list',
        },
        'gclid' => partnershipField('gclid', 255),
        'gbraid' => partnershipField('gbraid', 255),
        'wbraid' => partnershipField('wbraid', 255),
        'utm_source' => partnershipField('utm_source', 120),
        'utm_medium' => partnershipField('utm_medium', 120),
        'utm_campaign' => partnershipField('utm_campaign', 191),
        'utm_term' => partnershipField('utm_term', 191),
        'utm_content' => partnershipField('utm_content', 191),
        'landing_url' => partnershipField('landing_url', 500),
        '_remote_ip' => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
        '_ip_salt' => (string)($_ENV['APP_KEY'] ?? 'vnv-events'),
        '_user_agent' => (string)($_SERVER['HTTP_USER_AGENT'] ?? ''),
    ];

    $ownerId = partnershipOwnerId();
    $siteKey = SiteContext::siteKey();
    try {
        $opportunityId = (new CommercialPartnershipRepository())->createPublicOpportunity($ownerId, $siteKey, $program, $data);
    } catch (Throwable $e) {
        error_log('Commercial partnership intake error: ' . $e->getMessage());
        MessageUtil::setMessage('We could not save your request. Please try again or call (305) 204-5427.', 'Request', 'error');
        partnershipRedirect($program);
    }

    try {
        (new LeadIntakeRepository())->upsert($ownerId, [
            'source' => 'commercial_partnership',
            'external_id' => 'commercial-opportunity-' . $opportunityId,
            'channel' => 'website',
            'contact_name' => $contactName,
            'email' => $email,
            'phone' => $phone,
            'service_requested' => match ($program) {
                'NONPROFIT' => 'Nonprofit Partnership',
                'ACTIVATION' => 'Sponsor Activation Production',
                default => 'Original Experience Priority List: ' . $data['event_name'],
            },
            'guest_count' => $data['guest_count'] ?: null,
            'venue' => $data['venue'] ?: null,
            'event_date' => $eventDate ?: null,
            'setup_minutes' => 60,
            'availability_status' => 'NEEDS_MANUAL_REVIEW',
            'status' => 'NEW',
            'payload_json' => json_encode(['opportunity_id' => $opportunityId, 'program' => $program, 'data' => $data], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);
    } catch (Throwable $e) {
        error_log('Commercial partnership Lead Intake sync error: ' . $e->getMessage());
    }

    try {
        $emailService = new EmailService($ownerId);
        $label = match ($program) {
            'NONPROFIT' => 'Nonprofit Partnership application',
            'ACTIVATION' => 'Sponsor Activation brief',
            default => 'Original Experience priority-list request',
        };
        $safeName = htmlspecialchars($contactName, ENT_QUOTES, 'UTF-8');
        $requestSubject = $organizationName !== ''
            ? $organizationName
            : ((string)($data['event_name'] ?? '') !== '' ? (string)$data['event_name'] : $contactName);
        $safeOrganization = htmlspecialchars($requestSubject, ENT_QUOTES, 'UTF-8');
        $emailService->sendSimpleEmail(
            $email,
            'We received your VNV Events request #' . $opportunityId,
            '<div style="font-family:Arial,sans-serif;max-width:680px;margin:auto;color:#162124"><h2>Thank you, ' . $safeName . '.</h2><p>We received the ' . htmlspecialchars(strtolower($label)) . ' for <strong>' . $safeOrganization . '</strong>.</p><p>Our team will review the information and follow up. This acknowledgment is not an approval, funding commitment, sponsorship guarantee or confirmation of availability.</p><p>Reference: <strong>VNV-' . $opportunityId . '</strong></p></div>',
            true
        );

        $adminEmail = trim((string)($_ENV['EVENT_REQUEST_EMAIL'] ?? $_ENV['CONTACT_EMAIL'] ?? 'info@vnvevents.com'));
        if (filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
            $emailService->sendSimpleEmail(
                $adminEmail,
                'New ' . $label . ' #' . $opportunityId,
                '<div style="font-family:Arial,sans-serif"><h2>' . htmlspecialchars($label) . '</h2><p><strong>Organization/brand or experience:</strong> ' . $safeOrganization . '</p><p><strong>Contact:</strong> ' . $safeName . ' &middot; ' . htmlspecialchars($email) . ' &middot; ' . htmlspecialchars($phone) . '</p><p>Open VNV CRM &rarr; Partnership Pipelines to review and assign the next step.</p></div>',
                true
            );
        }
    } catch (Throwable $e) {
        error_log('Commercial partnership acknowledgment error #' . $opportunityId . ': ' . $e->getMessage());
    }

    $_SESSION['partnership_last_submit'] = $now;
    $_SESSION['partnership_csrf'] = bin2hex(random_bytes(24));
    MessageUtil::setMessage('Your request was received. Check your email for reference VNV-' . $opportunityId . '.', 'Thank you', 'success');
    partnershipRedirect($program, true);
});

$router->run();
