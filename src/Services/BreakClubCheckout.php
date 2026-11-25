<?php

namespace App\Services;

use App\Repositories\StripeAccountsRepository;
use App\Utils\SiteContext;
use DomainException;
use Stripe\StripeClient;

/** Direct, one-time card payments to the VNV Stripe Connect account used by event ticketing. */
class BreakClubCheckout
{
    public function account(int $owner): string
    {
        $row = (new StripeAccountsRepository())->getByUser($owner);
        if (!$row || empty($row->stripe_account_id) || empty($row->is_verified)) {
            throw new DomainException('Event checkout is not available yet. Please contact VNV Events.');
        }
        return $row->stripe_account_id;
    }

    private function client(): StripeClient
    {
        return new StripeClient(ConfigService::$STRIPE_KEY);
    }

    public function create(array $registration, string $account, int $attempt): object
    {
        $reference = [
            'vnv_program' => 'break_club',
            'break_registration_id' => (string)$registration['id'],
            'break_event_id' => (string)$registration['event_id'],
            'break_owner_id' => (string)$registration['owner_id'],
        ];
        $base = SiteContext::publicBaseUrl();
        return $this->client()->checkout->sessions->create([
            'mode' => 'payment',
            'payment_method_types' => ['card'],
            'adaptive_pricing' => ['enabled' => false],
            'client_reference_id' => (string)$registration['id'],
            'customer_email' => $registration['email'],
            'metadata' => $reference,
            'payment_intent_data' => ['metadata' => $reference],
            'line_items' => [[
                'quantity' => (int)$registration['party_size'],
                'price_data' => [
                    'currency' => 'usd',
                    'unit_amount' => (int)$registration['unit_cents'],
                    'product_data' => ['name' => $registration['event_title'] . ' · Break Club admission'],
                ],
            ]],
            'success_url' => $base . '/api/public/break-club/receipt?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $base . '/vnv-events-productions/mommy-and-daddy-break/?club=cancelled#waitlist',
        ], [
            'stripe_account' => $account,
            'idempotency_key' => 'break-club-' . $registration['owner_id'] . '-' . $registration['id'] . '-' . $attempt,
        ]);
    }

    public function session(string $id, string $account): object
    {
        return $this->client()->checkout->sessions->retrieve($id, [
            'expand' => ['payment_intent.latest_charge'],
        ], ['stripe_account' => $account]);
    }
}
