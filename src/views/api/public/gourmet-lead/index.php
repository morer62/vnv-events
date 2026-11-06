<?php

use App\Repositories\EventRequestRepository;
use App\Repositories\LeadIntakeRepository;
use App\Repositories\Connection;
use App\Services\GourmetLandingAttributionService;
use App\Utils\AvomealContext;
use App\Utils\Router;

$router = new Router();

$router->post(function (): void {
    $landing = GourmetLandingAttributionService::safeLandingPath((string)($_POST['landing_url'] ?? ''));
    $redirect = static function (string $query) use ($landing): void {
        header('Location: ' . $landing . '?' . $query . '#quote', true, 303);
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
    $attempts = array_values(array_filter((array)($_SESSION['gourmet_lead_attempts'] ?? []), fn($stamp) => (int)$stamp > $now - 900));
    if (count($attempts) >= 5) {
        $redirect('lead_error=rate');
    }
    $attempts[] = $now;
    $_SESSION['gourmet_lead_attempts'] = $attempts;

    $service = preg_replace('/[^a-z-]/', '', strtolower((string)($_POST['service'] ?? '')));
    $allowedServices = ['pasta', 'appetizer', 'crepes', 'taco', 'corporate'];
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

    $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $eventDate);
    $emailValid = $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL);
    $phoneValid = strlen(preg_replace('/\D+/', '', $phone)) >= 10;
    if (!$date || $date < new \DateTimeImmutable('today') || $guestCount <= 0 || !preg_match('/^\d{5}$/', $zip) || (!$emailValid && !$phoneValid)) {
        $redirect('lead_error=validation');
    }

    $attribution = GourmetLandingAttributionService::sanitize($_POST);
    $minimumGuests = $service === 'corporate' ? 50 : 25;
    $qualified = GourmetLandingAttributionService::isQualified($eventDate, $guestBucket, $minimumGuests, $zip, $email, $phone);
    $underMinimum = $guestBucket === 'fewer-than-25';
    $payload = [
        'ref_code' => $refCode,
        'service' => $service,
        'landing_url' => $landing,
        'guest_bucket' => $guestBucket,
        'zip' => $zip,
        'qualified_lead' => $qualified,
        'qualification_status' => $qualified ? 'QUALIFIED' : 'NEEDS_MANUAL_REVIEW',
        'gourmet_to_go_alternative' => $underMinimum,
        'attribution' => $attribution,
        'conversion_time' => gmdate('c'),
    ];

    $ownerId = AvomealContext::ownerId();
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
            'availability_status' => $qualified ? 'QUALIFIED' : 'NEEDS_MANUAL_REVIEW',
            'suggested_manager_id' => null,
            'availability_checked_at' => null,
            'status' => 'NEW',
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
