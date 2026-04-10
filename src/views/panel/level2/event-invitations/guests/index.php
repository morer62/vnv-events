<?php

use App\Repositories\EventsRepository;
use App\Repositories\EventGuestsRepository;
use App\Services\LoginService;
use App\Services\EventInvitationService;
use App\Utils\Router;
use App\Utils\TemplateResponse;
use App\Utils\MessageUtil;
use App\Utils\LocationUtils;
use PhpOffice\PhpSpreadsheet\IOFactory;

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
    
    $guestsRepo = new EventGuestsRepository();
    $guests = $guestsRepo->getAllByEvent($eventId);
    $stats = $eventsRepo->getEventStats($eventId);
    
    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "event" => $event,
        "guests" => $guests,
        "stats" => $stats,
        "message" => MessageUtil::getMessage()
    ]);
});

$router->post(function () {
    $user = LoginService::getSession();
    $action = $_POST["action"] ?? "";
    $eventId = intval($_POST["event_id"] ?? 0);
    
    $eventsRepo = new EventsRepository();
    $event = $eventsRepo->getOne(["id" => $eventId, "id_user" => $user->getId()]);
    
    if (!$event) {
        MessageUtil::setMessage("Event not found");
        LocationUtils::redirectInternal("panel/event-invitations");
    }
    
    $guestsRepo = new EventGuestsRepository();
    
    // Add single guest
    if ($action === "add_guest") {
        try {
            $accessToken = $guestsRepo->generateAccessToken();
            
            $guestsRepo->add([
                "id_event" => $eventId,
                "first_name" => $_POST["first_name"],
                "last_name" => $_POST["last_name"],
                "email" => $_POST["email"],
                "phone" => $_POST["phone"] ?? null,
                "guest_group" => $_POST["guest_group"] ?? null,
                "access_token" => $accessToken
            ]);
            
            MessageUtil::setMessage("Guest added successfully");
        } catch (Exception $e) {
            MessageUtil::setMessage("Error adding guest: " . $e->getMessage());
        }
        
        LocationUtils::redirectInternal("panel/event-invitations/guests?id=" . $eventId);
    }
    
    // Import Excel
    if ($action === "import_excel" && isset($_FILES["excel_file"])) {
        $file = $_FILES["excel_file"];
        
        if ($file["error"] === UPLOAD_ERR_OK) {
            try {
                $spreadsheet = IOFactory::load($file["tmp_name"]);
                $worksheet = $spreadsheet->getActiveSheet();
                $rows = $worksheet->toArray();
                
                $guestData = [];
                
                foreach ($rows as $index => $row) {
                    if ($index < 6) {
                        continue;
                    }
                    
                    $firstName = trim($row[0] ?? '');
                    $lastName = trim($row[1] ?? '');
                    $email = trim($row[2] ?? '');
                    $phone = trim($row[3] ?? '');
                    $guestGroup = trim($row[4] ?? '');
                    
                    if (!empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        $guestData[] = [
                            'first_name' => $firstName,
                            'last_name' => $lastName,
                            'email' => $email,
                            'phone' => $phone ?: null,
                            'guest_group' => $guestGroup ?: null
                        ];
                    }
                }
                
                $result = $guestsRepo->importFromCSV($eventId, $guestData);
                
                if (count($result['errors']) > 0) {
                    MessageUtil::setMessage("Imported {$result['imported']} guests. Errors: " . implode(', ', array_slice($result['errors'], 0, 3)), "Warning", "warning");
                } else {
                    MessageUtil::setMessage("✅ Successfully imported {$result['imported']} guests!");
                }
            } catch (\Exception $e) {
                MessageUtil::setMessage("Error reading Excel file: " . $e->getMessage(), "Error", "danger");
            }
        } else {
            MessageUtil::setMessage("Error uploading file", "Error", "danger");
        }
        
        LocationUtils::redirectInternal("panel/event-invitations/guests?id=" . $eventId);
    }
    
    // Send invitations
    if ($action === "send_invitations") {
        $guestIds = $_POST["guest_ids"] ?? [];
        
        $invitationService = new EventInvitationService();
        $result = $invitationService->sendBulkInvitations($eventId, $guestIds);
        
        MessageUtil::setMessage("Sent {$result['sent']} invitations. Failed: {$result['failed']}");
        LocationUtils::redirectInternal("panel/event-invitations/guests?id=" . $eventId);
    }
    
    // Delete guest
    if ($action === "delete_guest") {
        $guestId = intval($_POST["guest_id"]);
        $guestsRepo->delete(["id" => $guestId]);
        
        MessageUtil::setMessage("Guest deleted");
        LocationUtils::redirectInternal("panel/event-invitations/guests?id=" . $eventId);
    }
});

try {
    $router->run();
} catch (Exception $e) {
    echo $e->getMessage();
}

