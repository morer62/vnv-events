<?php

namespace App\Services;

use App\Repositories\EventsRepository;
use App\Repositories\EventGuestsRepository;

class EventInvitationService
{
    private EventsRepository $eventsRepo;
    private EventGuestsRepository $guestsRepo;
    private EmailService $emailService;

    public function __construct()
    {
        $this->eventsRepo = new EventsRepository();
        $this->guestsRepo = new EventGuestsRepository();
        $this->emailService = new EmailService();
    }

    public function sendInvitation(int $eventId, int $guestId): bool
    {
        $event = $this->eventsRepo->getOne(['id' => $eventId]);
        if (!$event) {
            throw new \Exception("Event not found");
        }

        $guest = $this->guestsRepo->getOne(['id' => $guestId]);
        if (!$guest) {
            throw new \Exception("Guest not found");
        }

        $invitationUrl = $this->generateInvitationUrl($event->slug, $guest->access_token);

        try {
            $subject = "🎉 You're Invited! " . $event->event_name;
            
            $templateData = [
                'event' => $event,
                'guest' => $guest,
                'invitationUrl' => $invitationUrl,
                'eventDate' => date('l, F j, Y', strtotime($event->event_date)),
                'eventTime' => date('g:i A', strtotime($event->event_time)),
                'rsvpDeadline' => $event->rsvp_deadline ? date('F j, Y', strtotime($event->rsvp_deadline)) : 'soon'
            ];
            
            $templatePath = \App\Utils\LocationUtils::getTemplatePath("emails/event_invitation.php");
            
            $this->emailService->sendTemplateEmail(
                $guest->email,
                $subject,
                $templatePath,
                $templateData
            );

            $this->guestsRepo->markInvitationSent($guestId);

            return true;
        } catch (\Exception $e) {
            error_log("Failed to send invitation: " . $e->getMessage());
            return false;
        }
    }

    public function sendBulkInvitations(int $eventId, array $guestIds = []): array
    {
        $guests = empty($guestIds) 
            ? $this->guestsRepo->getAllBy(['id_event' => $eventId, 'invitation_sent' => 0])
            : array_filter(
                $this->guestsRepo->getAllBy(['id_event' => $eventId]),
                fn($g) => in_array($g->id, $guestIds)
            );

        $sent = 0;
        $failed = 0;
        $errors = [];

        foreach ($guests as $guest) {
            try {
                if ($this->sendInvitation($eventId, $guest->id)) {
                    $sent++;
                } else {
                    $failed++;
                    $errors[] = "{$guest->email}: Failed to send";
                }
            } catch (\Exception $e) {
                $failed++;
                $errors[] = "{$guest->email}: " . $e->getMessage();
            }
        }

        return [
            'sent' => $sent,
            'failed' => $failed,
            'errors' => $errors
        ];
    }

    private function generateInvitationUrl(string $eventSlug, string $accessToken): string
    {
        $appUrl = rtrim($_ENV['APP_URL'] ?? 'http://localhost/vnv-venue', '/');
        return "{$appUrl}/event?slug={$eventSlug}&token={$accessToken}";
    }
}


