<?php

use App\Repositories\Connection;
use App\Repositories\EventRequestRepository;
use App\Repositories\LeadIntakeRepository;
use App\Services\GourmetLandingAttributionService;
use App\Services\GourmetLeadRateLimiter;
use App\Services\CateringLandingProductService;
use App\Utils\AvomealContext;
use App\Utils\Router;
use App\Utils\SiteContext;

$router = new Router();

$router->post(function (): void {
    $landing = GourmetLandingAttributionService::safeLandingPath((string)($_POST['landing_url'] ?? ''));
    $redirect = static function (string $query) use ($landing): void {
        header('Location: ' . $landing . '?' . $query . '#quote', true, 303);
        exit;
    };
    $rateLimited = static function () use ($landing): void {
        http_response_code(429);
        header('Content-Type: text/html; charset=utf-8');
        header('Retry-After: 600');
        $safeLanding = htmlspecialchars($landing, ENT_QUOTES, 'UTF-8');
        $whatsapp = htmlspecialchars((string)(CateringLandingProductService::comparisonContext()['whatsapp_number'] ?? ''), ENT_QUOTES, 'UTF-8');
        $whatsappLink = $whatsapp !== '' ? ' or <a href="https://wa.me/' . $whatsapp . '">message us on WhatsApp</a>' : '';
        echo '<!doctype html><html lang="en"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Please wait</title><body style="font-family:system-ui;max-width:42rem;margin:4rem auto;padding:1rem"><main><h1>Please wait a few minutes</h1><p>We received several requests from this connection. Please try again in 10 minutes.</p><p><a href="' . $safeLanding . '#quote">Return to the quote form</a>, <a href="tel:+13052045427">call VNV Events</a>' . $whatsappLink . '.</p></main></body></html>';
        exit;
    };

    $savedCsrf = (string)($_SESSION['csrf_token'] ?? '');
    $postedCsrf = (string)($_POST['csrf_token'] ?? '');
    if ($savedCsrf === '' || $postedCsrf === '' || !hash_equals($savedCsrf, $postedCsrf)) {
        $redirect('lead_error=csrf');
    }
    unset($_SESSION['csrf_token']);

    if (trim((string)($_POST['company_website'] ?? '')) !== '') {
        $redirect('lead=success');
    }

    $now = time();
    $attempts = array_values(array_filter(
        (array)($_SESSION['gourmet_lead_attempts'] ?? []),
        static fn($stamp): bool => (int)$stamp > $now - 600
    ));
    if (count($attempts) >= GourmetLeadRateLimiter::TEN_MINUTE_LIMIT) {
        $rateLimited();
    }
    $attempts[] = $now;
    $_SESSION['gourmet_lead_attempts'] = $attempts;

    $ownerId = AvomealContext::ownerId();
    $sharedDecision = (new GourmetLeadRateLimiter())->consume($ownerId, SiteContext::siteKey());
    if (!$sharedDecision['allowed']) {
        $rateLimited();
    }

    $service = preg_replace('/[^a-z-]/', '', strtolower((string)($_POST['service'] ?? '')));
    $allowedServices = ['pasta', 'appetizer', 'crepes', 'taco', 'corporate', 'not-sure-yet'];
    if (!in_array($service, $allowedServices, true)) {
        $redirect('lead_error=service');
    }

    $serviceName = mb_substr(trim((string)($_POST['service_name'] ?? 'VNV Gourmet')), 0, 120);
    $eventDate = trim((string)($_POST['event_date'] ?? ''));
    $guestBucket = trim((string)($_POST['guest_bucket'] ?? ''));
    $guestCount = GourmetLandingAttributionService::guestBucketMinimum($guestBucket);
    $zip = trim((string)($_POST['zip'] ?? ''));
    $fullName = mb_substr(trim((string)($_POST['full_name'] ?? '')), 0, 180);
    $email = mb_substr(trim((string)($_POST['email'] ?? '')), 0, 190);
    $phone = mb_substr(trim((string)($_POST['phone'] ?? '')), 0, 80);
    $refCode = mb_substr(preg_replace('/[^A-Za-z0-9-]/', '', (string)($_POST['ref_code'] ?? '')), 0, 40);
    $isTest = (string)($_POST['vnv_test'] ?? '') === '1';

    $emailValid = $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL);
    $phoneValid = strlen(preg_replace('/\D+/', '', $phone)) >= 10;
    if (!GourmetLandingAttributionService::isFutureEventDate($eventDate)
        || $guestCount <= 0
        || !preg_match('/^\d{5}$/', $zip)
        || (!$emailValid && !$phoneValid)
    ) {
        $redirect('lead_error=validation');
    }

    $attribution = GourmetLandingAttributionService::sanitize($_POST);
    $minimumGuests = $service === 'corporate' ? 50 : 25;
    $qualified = GourmetLandingAttributionService::isQualified($eventDate, $guestBucket, $minimumGuests, $zip, $email, $phone);
    $underMinimum = $guestBucket === 'fewer-than-25' || $guestCount < $minimumGuests;
    $zipClassification = GourmetLandingAttributionService::classifyZip($zip);
    $qualificationStatus = $qualified ? 'QUALIFIED' : match ($zipClassification['in_area']) {
        false => 'OUTSIDE_SERVICE_AREA',
        null => 'ZIP_NEEDS_MANUAL_REVIEW',
        default => $underMinimum ? 'UNDER_MINIMUM' : 'NEEDS_MANUAL_REVIEW',
    };
    $payload = [
        'ref_code' => $refCode,
        'service' => $service,
        'landing_url' => $landing,
        'guest_bucket' => $guestBucket,
        'zip' => $zip,
        'zip_classification' => $zipClassification,
        'qualified_lead' => $qualified,
        'qualification_status' => $qualificationStatus,
        'gourmet_to_go_alternative' => $underMinimum,
        'attribution' => $attribution,
        'is_test' => $isTest,
        'conversion_time' => GourmetLandingAttributionService::conversionTime(),
    ];

    $connection = new Connection();
    try {
        $connection->beginTransaction();
        $requestId = (new EventRequestRepository($connection))->createFromPublicForm([
            'id_owner' => $ownerId,
            'id_user' => null,
            'full_name' => $fullName !== '' ? $fullName : ($emailValid ? $email : 'VNV Gourmet lead'),
            'email' => $emailValid ? $email : '',
            'phone' => $phoneValid ? $phone : '',
            'event_address' => 'ZIP ' . $zip,
            'event_date' => $eventDate,
            'event_time' => null,
            'guest_count' => $guestCount,
            'selected_services' => json_encode([$serviceName], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'details' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'form_source' => 'gourmet_short_form',
            'status' => 'NEW',
            'is_archived' => 0,
        ]);

        $payload['event_request_id'] = $requestId;
        (new LeadIntakeRepository($connection))->upsert($ownerId, [
            'source' => 'gourmet_landing',
            'external_id' => 'gourmet-request-' . $requestId,
            'channel' => 'website',
            'contact_name' => $fullName !== '' ? $fullName : null,
            'email' => $emailValid ? $email : null,
            'phone' => $phoneValid ? $phone : null,
            'service_requested' => $serviceName,
            'guest_count' => $guestCount,
            'venue' => 'ZIP ' . $zip,
            'event_date' => $eventDate,
            'start_time' => null,
            'end_time' => null,
            'setup_minutes' => 0,
            'availability_status' => $qualificationStatus,
            'suggested_manager_id' => null,
            'availability_checked_at' => null,
            'status' => $isTest ? 'TEST' : 'NEW',
            'payload_json' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);
        $connection->commit();
    } catch (\Throwable $e) {
        try {
            $connection->rollback();
        } catch (\Throwable $rollbackError) {
            error_log('Gourmet short lead rollback failed: ' . $rollbackError->getMessage());
        }
        error_log('Gourmet short lead persistence failed: ' . $e->getMessage());
        $redirect('lead_error=persistence');
    }

    $redirect('lead=success');
});

$router->run();
