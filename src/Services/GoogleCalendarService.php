<?php

namespace App\Services;

use Google\Client;
use Google\Service\Calendar;
use Google\Service\Calendar\Event;
use Google\Service\Calendar\EventDateTime;
use Exception;

class GoogleCalendarService
{
    private $client;
    private $calendarService;
    
    public function __construct()
    {
        $this->client = new Client();
        $this->client->setApplicationName('Venue Management System');
        $this->client->setScopes(Calendar::CALENDAR);
        
        $this->client->setAuthConfig($_ENV['GOOGLE_SERVICE_ACCOUNT_JSON_PATH']);
        $this->client->useApplicationDefaultCredentials();
        
        $this->calendarService = new Calendar($this->client);
    }
    
    /**
     * Crea un evento en Google Calendar para un venue nuevo
     */
    public function createVenueEvent($venueData, $userEmail)
    {
        try {
            $event = new Event([
                'summary' => 'New Venue Submission: ' . $venueData['name'],
                'description' => $this->buildEventDescription($venueData),
                'start' => [
                    'dateTime' => date('c'), // Fecha actual en formato ISO 8601
                    'timeZone' => 'America/New_York', // Ajusta según tu zona horaria
                ],
                'end' => [
                    'dateTime' => date('c', strtotime('+1 hour')), // 1 hora después
                    'timeZone' => 'America/New_York',
                ],
                'attendees' => [
                    ['email' => $userEmail],
                    ['email' => $_ENV['ADMIN_EMAIL']] // Email del administrador
                ],
                'reminders' => [
                    'useDefault' => false,
                    'overrides' => [
                        ['method' => 'email', 'minutes' => 24 * 60], // 24 horas antes
                        ['method' => 'popup', 'minutes' => 30], // 30 minutos antes
                    ],
                ],
                'location' => $venueData['address'],
                'colorId' => '2', // Color verde para venues nuevos
                'status' => 'confirmed',
                'visibility' => 'private'
            ]);
            
            $calendarId = $_ENV['GOOGLE_CALENDAR_ID'] ?? 'primary';
            
            $createdEvent = $this->calendarService->events->insert($calendarId, $event);
            
            return [
                'success' => true,
                'event_id' => $createdEvent->getId(),
                'event_link' => $createdEvent->getHtmlLink(),
                'message' => 'Event created successfully in Google Calendar'
            ];
            
        } catch (Exception $e) {
            error_log("Google Calendar Error: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'message' => 'Failed to create calendar event'
            ];
        }
    }
    
    /**
     * Construye la descripción del evento con los datos del venue
     */
    private function buildEventDescription($venueData)
    {
        $description = "New venue submission details:\n\n";
        $description .= "Venue Name: " . $venueData['name'] . "\n";
        $description .= "Category: " . ($venueData['category_name'] ?? 'N/A') . "\n";
        $description .= "Address: " . $venueData['address'] . "\n";
        $description .= "Capacity: " . ($venueData['capacity'] ?? 'N/A') . " guests\n";
        $description .= "Base Price: $" . ($venueData['base_price'] ?? '0') . "\n";
        $description .= "Business Name: " . $venueData['business_name'] . "\n";
        $description .= "Contact Email: " . $venueData['email'] . "\n";
        $description .= "Phone: " . $venueData['phone_number'] . "\n";
        
        if (!empty($venueData['website'])) {
            $description .= "Website: " . $venueData['website'] . "\n";
        }
        
        $description .= "\nStatus: Pending Approval\n";
        $description .= "Submitted: " . date('Y-m-d H:i:s') . "\n\n";
        $description .= "Please review and approve this venue submission.";
        
        return $description;
    }
    
    /**
     * Actualiza un evento existente cuando cambia el status del venue
     */
    public function updateVenueEventStatus($eventId, $status, $venueData)
    {
        try {
            $event = $this->calendarService->events->get('primary', $eventId);
            
            // Actualizar el título y descripción según el status
            $statusColors = [
                'approved' => '10', // Verde
                'rejected' => '11', // Rojo
                'pending' => '2'    // Azul
            ];
            
            $event->setSummary('Venue ' . ucfirst($status) . ': ' . $venueData['name']);
            $event->setColorId($statusColors[$status] ?? '2');
            
            $updatedDescription = $event->getDescription() . "\n\nStatus Updated: " . ucfirst($status) . " on " . date('Y-m-d H:i:s');
            $event->setDescription($updatedDescription);
            
            $this->calendarService->events->update('primary', $eventId, $event);
            
            return ['success' => true, 'message' => 'Event updated successfully'];
            
        } catch (Exception $e) {
            error_log("Google Calendar Update Error: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Añade un evento de orden al calendario del cliente
     */
    public function addEventToClientCalendar($client, $order)
    {
        try {
            $clientGoogleClient = new Client();
            $clientGoogleClient->setClientId($_ENV['GOOGLE_CLIENT_ID']);
            $clientGoogleClient->setClientSecret($_ENV['GOOGLE_CLIENT_SECRET']);
            
            $token = json_decode($client->google_token, true);
            $clientGoogleClient->setAccessToken($token);
            
            // Refresh token if needed
            if ($clientGoogleClient->isAccessTokenExpired()) {
                if (isset($token['refresh_token'])) {
                    $clientGoogleClient->fetchAccessTokenWithRefreshToken($token['refresh_token']);
                    $newToken = $clientGoogleClient->getAccessToken();
                    
                    // Update token in database
                    $userRepo = new \App\Repositories\UserRepository();
                    $userRepo->update([
                        'google_token' => json_encode($newToken)
                    ], ['id' => $client->id]);
                } else {
                    throw new Exception('Google token expired and no refresh token available');
                }
            }

            $clientCalendarService = new Calendar($clientGoogleClient);

            // Build start/end. If times are missing, create all-day event
            $hasStart = !empty($order->start_time);
            $hasEnd = !empty($order->end_time);
            $isAllDay = !$hasStart || !$hasEnd;

            if ($isAllDay) {
                $start = ['date' => $order->event_date, 'timeZone' => 'America/New_York'];
                // end date must be next day for all-day events per Google API
                $nextDay = date('Y-m-d', strtotime($order->event_date . ' +1 day'));
                $end = ['date' => $nextDay, 'timeZone' => 'America/New_York'];
            } else {
                $startDateTime = $order->event_date . 'T' . $order->start_time;
                $endDateTime = $order->event_date . 'T' . $order->end_time;
                $start = ['dateTime' => $startDateTime, 'timeZone' => 'America/New_York'];
                $end = ['dateTime' => $endDateTime, 'timeZone' => 'America/New_York'];
            }

            $event = new Event([
                'summary' => 'Event: Order VNV-341' . $order->id,
                'description' => $this->buildOrderEventDescription($order),
                'start' => $start,
                'end' => $end,
                'location' => $order->address,
                'reminders' => [
                    'useDefault' => false,
                    'overrides' => [
                        ['method' => 'email', 'minutes' => 24 * 60], // 24 horas antes
                        ['method' => 'popup', 'minutes' => 60], // 1 hora antes
                    ],
                ],
                'colorId' => '9', // Color azul para eventos
                'status' => 'confirmed'
            ]);

            $createdEvent = $clientCalendarService->events->insert('primary', $event);
            return $createdEvent->getId();

        } catch (Exception $e) {
            error_log("Google Calendar Error: " . $e->getMessage());
            if (isset($e->errors)) {
                error_log('Google errors: ' . json_encode($e->errors));
            }
            return false;
        }
    }

    /**
     * Construye la descripción del evento para la orden
     */
    private function buildOrderEventDescription($order)
    {
        $description = "Event Details:\n\n";
        $description .= "Order ID: VNV-341" . $order->id . "\n";
        $description .= "Date: " . date('l, F j, Y', strtotime($order->event_date)) . "\n";
        $description .= "Time: " . date('g:i A', strtotime($order->start_time)) . " - " . date('g:i A', strtotime($order->end_time)) . "\n";
        $description .= "Location: " . $order->address . "\n\n";
        
        if (!empty($order->notes)) {
            $description .= "Event Notes:\n" . $order->notes . "\n\n";
        }
        
        $description .= "This event was automatically added from your E-Planner Hub order.";
        
        return $description;
    }
}