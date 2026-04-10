<?php

use App\Repositories\EventsRepository;
use App\Services\LoginService;
use App\Utils\FileUtils;
use App\Utils\Router;
use App\Utils\TemplateResponse;
use App\Utils\MessageUtil;
use App\Utils\LocationUtils;

$router = new Router();

$router->get(function () {
    $user = LoginService::getSession();
    $eventId = intval($_GET["id"] ?? 0);
    
    $eventsRepo = new EventsRepository();
    $event = $eventsRepo->getOne(["id" => $eventId, "id_user" => $user->getId()]);
    
    if (!$event) {
        MessageUtil::setMessage("Event not found");
        LocationUtils::redirectInternal("panel/event-invitations");
    }
    
    $eventTypes = EventsRepository::EVENT_TYPES;
    $stats = $eventsRepo->getEventStats($eventId);
    
    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "event" => $event,
        "eventTypes" => $eventTypes,
        "stats" => $stats,
        "message" => MessageUtil::getMessage()
    ]);
});

$router->post(function () {
    $user = LoginService::getSession();
    $eventId = intval($_POST["event_id"] ?? 0);
    $action = $_POST["action"] ?? "";
    
    $eventsRepo = new EventsRepository();
    $event = $eventsRepo->getOne(["id" => $eventId, "id_user" => $user->getId()]);
    
    if (!$event) {
        MessageUtil::setMessage("Event not found");
        LocationUtils::redirectInternal("panel/event-invitations");
    }
    
    // Update event details
    if ($action === "update_event") {
        $updateData = [
            "event_name" => $_POST["event_name"],
            "event_type" => $_POST["event_type"],
            "event_description" => $_POST["event_description"] ?? null,
            "event_date" => $_POST["event_date"],
            "event_time" => $_POST["event_time"],
            "event_end_time" => $_POST["event_end_time"] ?? null,
            "venue_name" => $_POST["venue_name"] ?? null,
            "venue_address" => $_POST["venue_address"] ?? null,
            "venue_city" => $_POST["venue_city"] ?? null,
            "venue_state" => $_POST["venue_state"] ?? null,
            "venue_zip" => $_POST["venue_zip"] ?? null,
            "max_guests" => intval($_POST["max_guests"] ?? 0),
            "expected_guests" => intval($_POST["expected_guests"] ?? 0),
            "custom_message" => $_POST["custom_message"] ?? null,
            "dress_code" => $_POST["dress_code"] ?? null,
            "rsvp_deadline" => $_POST["rsvp_deadline"] ?? null,
            "allow_plus_ones" => isset($_POST["allow_plus_ones"]) ? 1 : 0,
            "max_plus_ones_per_guest" => intval($_POST["max_plus_ones_per_guest"] ?? 5)
        ];
        
        $result = $eventsRepo->updateWithDateCheck($eventId, $updateData);
        
        if ($result['success']) {
            MessageUtil::setMessage($result['message']);
        } else {
            MessageUtil::setMessage($result['message'], "Error", "danger");
        }
        
        LocationUtils::redirectInternal("panel/event-invitations/manage?id=" . $eventId);
    }
    
    // Update design
    if ($action === "update_design") {
        $updateData = [
            "template_id" => intval($_POST["template_id"] ?? 1),
            "primary_color" => $_POST["primary_color"] ?? '#3B82F6',
            "secondary_color" => $_POST["secondary_color"] ?? '#8B5CF6',
            "font_family" => $_POST["font_family"] ?? 'Poppins',
            "header_text_color" => $_POST["header_text_color"] ?? '#FFFFFF'
        ];
        
        // Handle delete cover image
        if (isset($_POST["delete_cover_image"]) && $_POST["delete_cover_image"] === "1") {
            if ($event->cover_image_url) {
                try {
                    FileUtils::removeFile($event->cover_image_url);
                } catch (Exception $e) {
                    error_log("Error deleting cover image: " . $e->getMessage());
                }
            }
            $updateData["cover_image_url"] = null;
        }
        
        // Handle cover image upload
        if (!empty($_FILES) && isset($_FILES["cover_image"]) && $_FILES["cover_image"]["error"] == 0) {
            try {
                // Delete old image if exists
                if ($event->cover_image_url) {
                    FileUtils::removeFile($event->cover_image_url);
                }
                
                $coverImageUrl = FileUtils::saveFile($_FILES["cover_image"], "event-cover-images");
                $updateData["cover_image_url"] = $coverImageUrl;
            } catch (Exception $e) {
                error_log("Error uploading cover image: " . $e->getMessage());
            }
        }
        
        $eventsRepo->update($updateData, ["id" => $eventId]);
        
        MessageUtil::setMessage("Design updated successfully");
        LocationUtils::redirectInternal("panel/event-invitations/manage?id=" . $eventId);
    }
    
    // Change status
    if ($action === "change_status") {
        $status = $_POST["status"] ?? 'active';
        
        $eventsRepo->update(["status" => $status], ["id" => $eventId]);
        
        MessageUtil::setMessage("Event status updated to: " . $status);
        LocationUtils::redirectInternal("panel/event-invitations/manage?id=" . $eventId);
    }
});

try {
    $router->run();
} catch (Exception $e) {
    echo $e->getMessage();
}

