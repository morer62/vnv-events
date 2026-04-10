<?php

use App\Repositories\ClientsRequestRepository;
use App\Repositories\UserRepository;
use App\Services\EmailService;
use App\Repositories\NotificationsRepository;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();

// VISTA GET
$router->get(function () {
    $profileCat = $_GET['profile_cat'] ?? null;
    $profileId = $_GET['id'] ?? null;

    if (!in_array($profileCat, ['venue', 'vendor']) || !is_numeric($profileId)) {
        return "Invalid request";
    }

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        'profile_cat' => $profileCat,
        'profile_id' => (int)$profileId,
        'success' => $_GET['success'] ?? null,
        'app_url' => $_ENV["APP_URL"] ?? '/'
    ]);
});

$router->post(function () {
    try {
        $repo = new ClientsRequestRepository();

        // Sanitize and validate input data
        $data = [
            'profile_cat'     => trim($_POST['profile_cat'] ?? ''),
            'profile_id'      => (int)($_POST['profile_id'] ?? 0),
            'event_date'      => trim($_POST['event_date'] ?? ''),
            'event_time'      => trim($_POST['event_time'] ?? ''),
            'event_duration'  => floatval($_POST['event_duration'] ?? 0),
            'guests'          => !empty($_POST['guests']) ? (int)$_POST['guests'] : null,
            'budget'          => !empty($_POST['budget']) ? floatval($_POST['budget']) : null,
            'event_type'      => trim($_POST['event_type'] ?? ''),
            'details'         => trim(substr($_POST['details'] ?? '', 0, 240)), // Limit to 240 chars
            'client_name'     => trim($_POST['client_name'] ?? ''),
            'client_phone'    => trim($_POST['client_phone'] ?? ''),
            'client_email'    => trim($_POST['client_email'] ?? ''),
            'client_address'  => trim($_POST['client_address'] ?? ''),
            'created_at'      => date('Y-m-d H:i:s'),
            'status'            => 'PENDING' 
        ];

        // Enhanced validation
        $errors = [];
        
        // Required field validation
        if (empty($data['client_name'])) {
            $errors[] = "Full name is required";
        }
        
        if (empty($data['client_email'])) {
            $errors[] = "Email address is required";
        } elseif (!filter_var($data['client_email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Please enter a valid email address";
        }
        
        if (empty($data['event_date'])) {
            $errors[] = "Event date is required";
        } elseif (!validateDate($data['event_date'])) {
            $errors[] = "Please enter a valid event date";
        } elseif (strtotime($data['event_date']) < strtotime('today')) {
            $errors[] = "Event date cannot be in the past";
        }
        
        if (empty($data['event_time'])) {
            $errors[] = "Event time is required";
        }
        
        if ($data['event_duration'] <= 0) {
            $errors[] = "Event duration must be greater than 0";
        }
        
        if (!in_array($data['profile_cat'], ['venue', 'vendor'])) {
            $errors[] = "Invalid profile category";
        }
        
        if ($data['profile_id'] <= 0) {
            $errors[] = "Invalid profile ID";
        }
        
        if (empty($data['client_address'])) {
            $errors[] = "Event address is required";
        }

        // If there are validation errors, return them
        if (!empty($errors)) {
            MessageUtil::setMessage("Please correct the following errors: " . implode(", ", $errors));
            LocationUtils::redirectTo($_SERVER['HTTP_REFERER'] ?? "/search");
            return;
        }

        // Attempt to save the data
        $result = $repo->add($data);
        
        if ($result) {
            // Send notification and email to vendor/venue owner
            try {
                $userRepo = new UserRepository();
                $emailService = new EmailService();
                $notificationsRepo = new NotificationsRepository();
                
                // Get vendor/venue owner information
                $ownerInfo = null;
                if ($data['profile_cat'] === 'venue') {
                    try {
                        $db = new \App\Repositories\Connection();
                        
                        // Get venue owner
                        $venueQuery = "SELECT v.*, u.name, u.lastname, u.email FROM venues v 
                                       INNER JOIN users u ON v.user_id = u.id 
                                       WHERE v.id = :venue_id";
                        $db->query($venueQuery);
                        $db->bind(':venue_id', $data['profile_id']);
                        $ownerInfo = $db->fetchOne();
                    } catch (\Exception $e) {
                        $ownerInfo = null;
                    }
                } elseif ($data['profile_cat'] === 'vendor') {
                    try {
                        $db = new \App\Repositories\Connection();
                        
                        // Get vendor owner
                        $vendorQuery = "SELECT s.*, u.name, u.lastname, u.email FROM service s 
                                        INNER JOIN users u ON s.user_id = u.id 
                                        WHERE s.id = :service_id";
                        $db->query($vendorQuery);
                        $db->bind(':service_id', $data['profile_id']);
                        $ownerInfo = $db->fetchOne();
                    } catch (\Exception $e) {
                        $ownerInfo = null;
                    }
                }
                
                if ($ownerInfo && $ownerInfo->email) {
                    // Create database notification
                    $notificationMessage = "📨 New Request Received - A client has requested your " . 
                                         ($data['profile_cat'] === 'venue' ? 'venue' : 'service') . 
                                         " for " . date("F j, Y", strtotime($data['event_date'])) . 
                                         " at " . date("g:i A", strtotime($data['event_time']));
                    
                    // URL correcta según el tipo de perfil
                    $notificationUrl = ($data['profile_cat'] === 'venue') 
                        ? ($_ENV["APP_URL"] ?? "http://localhost/vnv-venue") . "/panel/venues/home"
                        : ($_ENV["APP_URL"] ?? "http://localhost/vnv-venue") . "/panel/service/home";
                    
                    $notificationsRepo->add([
                        "id_user" => $ownerInfo->user_id,
                        "mensaje" => $notificationMessage,
                        "link" => $notificationUrl,
                        "leido" => 0
                    ]);
                    
                    // Send email notification
                    $subject = "📨 New Client Request - " . ($data['profile_cat'] === 'venue' ? 'Venue' : 'Service') . " Inquiry";
                    
                    $templateData = [
                        'ownerName' => $ownerInfo->name . ' ' . $ownerInfo->lastname,
                        'profileType' => $data['profile_cat'] === 'venue' ? 'venue' : 'service',
                        'profileName' => $data['profile_cat'] === 'venue' ? $ownerInfo->name : $ownerInfo->name,
                        'clientName' => $data['client_name'],
                        'clientEmail' => $data['client_email'],
                        'clientPhone' => $data['client_phone'] ?: 'Not provided',
                        'eventDate' => date("F j, Y", strtotime($data['event_date'])),
                        'eventTime' => date("g:i A", strtotime($data['event_time'])),
                        'eventDuration' => $data['event_duration'] . ' hours',
                        'guests' => $data['guests'] ?: 'Not specified',
                        'budget' => $data['budget'] ? '$' . number_format($data['budget'], 2) : 'Not specified',
                        'eventType' => $data['event_type'] ?: 'Not specified',
                        'eventAddress' => $data['client_address'],
                        'details' => $data['details'] ?: 'No additional details provided',
                        'requestUrl' => $notificationUrl
                    ];
                    
                    $templatePath = \App\Utils\LocationUtils::getTemplatePath("emails/client_request_notification.php");
                    
                    $emailService->sendTemplateEmail(
                        $ownerInfo->email,
                        $subject,
                        $templatePath,
                        $templateData
                    );
                }
            } catch (\Exception $e) {
                // Don't fail the request if notification fails
            }
            
            // Success - redirect back to the same form with success parameter
            $currentUrl = $_SERVER['REQUEST_URI'];
            $separator = strpos($currentUrl, '?') !== false ? '&' : '?';
            $redirectUrl = $currentUrl . $separator . 'success=1';
            LocationUtils::redirectTo($redirectUrl);
        } else {
            // Database error
            MessageUtil::setMessage("Sorry, there was an error processing your request. Please try again.");
            LocationUtils::redirectTo($_SERVER['HTTP_REFERER'] ?? "/search");
        }

    } catch (Exception $e) {
        // Log the error (you might want to use a proper logging system)
        error_log("Client Request Error: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
        
        // Set user-friendly error message
        MessageUtil::setMessage("Sorry, there was a technical problem. Please try again later.");
        LocationUtils::redirectTo($_SERVER['HTTP_REFERER'] ?? "/search");
    }
});

/**
 * Validate date format
 */
function validateDate($date, $format = 'Y-m-d') {
    $d = DateTime::createFromFormat($format, $date);
    return $d && $d->format($format) === $date;
}

try {
    $router->run();
} catch (Exception $e) {
    error_log("Router Error: " . $e->getMessage());
    MessageUtil::setMessage("Sorry, there was a system error. Please try again.");
    LocationUtils::redirectTo("/search");
}