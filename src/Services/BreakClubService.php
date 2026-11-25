<?php

namespace App\Services;

use App\Entity\User;
use App\Repositories\Connection;
use App\Utils\SiteContext;
use App\Utils\TemplateResponse;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use RuntimeException;
use Throwable;

final class BreakClubService
{
    public const LANDING = 'vnv-events-productions/mommy-and-daddy-break/';
    public const CATEGORIES = ['SOCIAL' => 'Social mornings', 'COFFEE' => 'Coffee & brunch', 'WELLNESS' => 'Wellness', 'MUSIC' => 'Music'];
    private Connection $db;
    private int $owner;
    private BreakClubCheckout $payments;
    private \Closure $send;

    public function __construct(?Connection $db = null, ?BreakClubCheckout $payments = null, ?\Closure $send = null)
    {
        if (SiteContext::siteKey() !== 'vnvevents') {
            throw new DomainException('This club belongs to VNV Events.');
        }
        $this->owner = SiteContext::businessOwnerId();
        $this->db = $db ?? new Connection();
        $this->payments = $payments ?? new BreakClubCheckout();
        $this->send = $send ?? fn(string $email, string $subject, string $body) =>
            (new EmailService($this->owner))->sendSimpleEmail($email, $subject, $body, false);
    }

    public static function csrfToken(): string
    {
        return $_SESSION['break_club_csrf'] ??= bin2hex(random_bytes(32));
    }

    public static function requireCsrf(mixed $token): void
    {
        if (!is_string($token) || !hash_equals(self::csrfToken(), $token)) {
            throw new DomainException('Your session expired. Refresh the page and try again.');
        }
    }

    public static function hasVerifiedLogin(User $user): bool
    {
        return (int)($_SESSION['vnv_credentials_verified_for'] ?? 0) === (int)$user->getId()
            && (int)$user->getId() > 0;
    }

    public static function signInPrompt(User $user): string
    {
        return TemplateResponse::render(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR
            . 'api/public/break-club/access/index.twig', [
                'purpose'=>'SIGN_IN', 'csrf'=>self::csrfToken(),
                'target'=>$user->getLevel() === 1 ? 'admin' : 'member',
            ]);
    }

    private function query(string $sql, array $params): void
    {
        $this->db->query($sql);
        foreach ($params as $key => $value) {
            $this->db->bind(':' . $key, $value);
        }
    }

    private function one(string $sql, array $params = []): ?array
    {
        $this->query($sql, $params);
        $row = $this->db->fetchOne();
        return $row ? (array)$row : null;
    }

    private function many(string $sql, array $params = []): array
    {
        $this->query($sql, $params);
        return array_map(static fn(object $row) => (array)$row, $this->db->fetchAll());
    }

    private function write(string $sql, array $params): void
    {
        $this->query($sql, $params);
        $this->db->execute();
    }

    private function transaction(callable $action): mixed
    {
        $this->db->beginTransaction();
        try {
            $result = $action();
            $this->db->commit();
            return $result;
        } catch (Throwable $error) {
            $this->db->rollback();
            throw $error;
        }
    }

    private static function field(array $data, string $key, int $max): string
    {
        $value = $data[$key] ?? '';
        if (!is_string($value) && !is_int($value)) {
            throw new DomainException('Please check the information entered.');
        }
        $value = trim((string)$value);
        if (mb_strlen($value) > $max || preg_match('/[\x00-\x1f\x7f]/u', $value)) {
            throw new DomainException('Please shorten or correct the ' . str_replace('_', ' ', $key) . ' field.');
        }
        return $value;
    }

    public static function applicant(array $data): array
    {
        $name = self::field($data, 'name', 150);
        $email = strtolower(self::field($data, 'email', 190));
        $phone = self::field($data, 'phone', 32);
        $size = self::field($data, 'party_size', 1);
        $category = self::field($data, 'category', 24) ?: 'SOCIAL';
        if (!$name || !filter_var($email, FILTER_VALIDATE_EMAIL)
            || !preg_match('/^[+0-9().\s-]+$/D', $phone) || !preg_match('/^\d{7,15}$/D', preg_replace('/\D/', '', $phone))) {
            throw new DomainException('Enter your full name, email and a valid phone number.');
        }
        if (!isset(self::CATEGORIES[$category]) || !in_array($size, ['1','2'], true)) {
            throw new DomainException('Choose an interest and a party of one or two adults.');
        }
        if (($data['consent'] ?? '') !== '1') {
            throw new DomainException('Please confirm that you want to receive club invitations.');
        }
        $companion = $size === '2' ? self::field($data, 'companion_name', 150) : '';
        $guestEmail = $size === '2' ? strtolower(self::field($data, 'companion_email', 190)) : '';
        if (($size === '2' && !$companion) || ($guestEmail !== '' && !filter_var($guestEmail, FILTER_VALIDATE_EMAIL))) {
            throw new DomainException('Enter the name of your guest and check their optional email address.');
        }
        return ['name'=>$name, 'email'=>$email, 'phone'=>$phone, 'category'=>$category,
            'party_size'=>(int)$size, 'companion_name'=>$companion ?: null, 'companion_email'=>$guestEmail ?: null];
    }

    public function join(array $input): void
    {
        $data = self::applicant($input);
        $campaign = [];
        foreach (['utm_source','utm_campaign','utm_content'] as $key) {
            $value = $input[$key] ?? '';
            $campaign[$key] = is_string($value) && preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._ -]{0,99}$/D',$value) ? $value : null;
        }
        // Keep the waitlist request even if the mail provider is temporarily unavailable.
        $this->write("INSERT INTO vnv_break_members
            (owner_id,name,email,phone,category,party_size,companion_name,companion_email,consent_at,utm_source,utm_campaign,utm_content)
            VALUES (:owner,:name,:email,:phone,:category,:party_size,:companion_name,:companion_email,UTC_TIMESTAMP(),:utm_source,:utm_campaign,:utm_content)
            ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)", ['owner'=>$this->owner] + $data + $campaign);
        $this->transaction(function () use ($data) {
            $member = $this->one('SELECT * FROM vnv_break_members WHERE owner_id=:owner AND email=:email FOR UPDATE',
                ['owner'=>$this->owner, 'email'=>$data['email']]);
            if ($member['status'] === 'ACTIVE' || $this->recentToken((int)$member['id'], 'VERIFY')) {
                return;
            }
            // Refresh unverified applicant details together with their proof; used proofs cannot confirm new details.
            $this->write("UPDATE vnv_break_email_tokens SET used_at=UTC_TIMESTAMP() WHERE member_id=:id AND purpose='VERIFY' AND used_at IS NULL", ['id'=>$member['id']]);
            unset($data['email']);
            $this->write('UPDATE vnv_break_members SET name=:name, phone=:phone, category=:category, party_size=:party_size,
                companion_name=:companion_name, companion_email=:companion_email, consent_at=UTC_TIMESTAMP() WHERE id=:id',
                $data + ['id'=>$member['id']]);
            $link = $this->issue((int)$member['id'], 'VERIFY');
            $this->mail($member['email'], 'Confirm your Mommy & Daddy Break Club invitations',
                "Confirm your email to join the free invitation list for Mommy & Daddy Break Club.\n\n$link\n\nEach event has a separate admission price. If you did not request this, you can disregard this email.");
        });
    }

    private function recentToken(int $member, string $purpose): bool
    {
        return (bool)$this->one('SELECT 1 FROM vnv_break_email_tokens WHERE member_id=:id AND purpose=:purpose
            AND used_at IS NULL AND created_at > UTC_TIMESTAMP() - INTERVAL 5 MINUTE LIMIT 1', ['id'=>$member,'purpose'=>$purpose]);
    }

    private function issue(int $member, string $purpose, ?int $registration = null, ?int $user = null): string
    {
        $lifetime = ['VERIFY'=>'2 DAY','CLAIM'=>'30 MINUTE','INVITE'=>'1 YEAR','UNSUBSCRIBE'=>'10 YEAR'][$purpose];
        $value = bin2hex(random_bytes(32));
        $this->write("INSERT INTO vnv_break_email_tokens
            (token_hash,member_id,registration_id,target_user_id,purpose,created_at,expires_at)
            VALUES (:hash,:member,:registration,:user,:purpose,UTC_TIMESTAMP(),UTC_TIMESTAMP() + INTERVAL $lifetime)",
            ['hash'=>hash('sha256',$value),'member'=>$member,'registration'=>$registration,'user'=>$user,'purpose'=>$purpose]);
        return SiteContext::publicBaseUrl() . '/api/public/break-club/access?token=' . $value;
    }

    private function mail(string $to, string $subject, string $body): void
    {
        if (!(($this->send)($to, $subject, $body))) {
            throw new RuntimeException('Club email delivery failed.');
        }
    }

    private function matchingUser(?User $user, string $email): bool
    {
        return $user && self::hasVerifiedLogin($user) && $user->getLevel() === 5 && (int)$user->getIdOwner() === $this->owner
            && strcasecmp(trim($user->getEmail()), $email) === 0;
    }

    public function memberForUser(User $user): ?array
    {
        if (!$this->matchingUser($user, $user->getEmail())) {
            return null;
        }
        return $this->one("SELECT * FROM vnv_break_members WHERE owner_id=:owner AND user_id=:user
            AND email=:email AND confirmed_at IS NOT NULL", ['owner'=>$this->owner,'user'=>(int)$user->getId(),'email'=>trim($user->getEmail())]);
    }

    public function claimForUser(User $user): void
    {
        if (!$this->matchingUser($user, $user->getEmail())) {
            return;
        }
        $this->transaction(function () use ($user) {
            $member = $this->one("SELECT * FROM vnv_break_members WHERE owner_id=:owner AND email=:email FOR UPDATE",
                ['owner'=>$this->owner,'email'=>trim($user->getEmail())]);
            if (!$member || $member['user_id'] || $member['status'] !== 'ACTIVE' || $this->recentToken((int)$member['id'],'CLAIM')) {
                return;
            }
            $link = $this->issue((int)$member['id'], 'CLAIM', null, (int)$user->getId());
            $this->mail($member['email'], 'Connect your Break Club membership to your VNV account',
                "Sign in to your VNV client account and open this link to connect your club membership. This link lasts 30 minutes.\n\n$link");
        });
    }

    private function token(string $token, bool $lock = false): array
    {
        if (!preg_match('/^[0-9a-f]{64}$/D', $token)) {
            throw new DomainException('This club link is invalid or has expired.');
        }
        $row = $this->one('SELECT t.member_id,t.registration_id,t.target_user_id,t.purpose,m.status,m.user_id,m.email
            FROM vnv_break_email_tokens t JOIN vnv_break_members m ON m.id=t.member_id
            WHERE m.owner_id=:owner AND t.token_hash=:hash AND t.used_at IS NULL AND t.expires_at>UTC_TIMESTAMP()' . ($lock ? ' FOR UPDATE' : ''),
            ['owner'=>$this->owner,'hash'=>hash('sha256',$token)]);
        if (!$row) {
            throw new DomainException('This club link has expired or was already used.');
        }
        return $row;
    }

    public function linkDetails(string $token): array
    {
        $link = $this->token($token);
        $details = ['purpose'=>$link['purpose']];
        if ($link['purpose'] === 'INVITE') {
            $reg = $this->registration((int)$link['registration_id']);
            $details += ['title'=>$reg['event_title'],'starts_at'=>$reg['starts_at'],'location'=>$reg['location'],
                'total_cents'=>$reg['unit_cents'] * $reg['party_size'],'party_size'=>$reg['party_size'],'status'=>$reg['status']];
        }
        return $details;
    }

    /** POST only. Email security scanners may visit the GET preview. */
    public function useLink(string $token, ?User $user): string
    {
        $link = $this->token($token);
        if ($link['purpose'] === 'INVITE') {
            $reg = $this->registration((int)$link['registration_id']);
            return $this->checkout((int)$reg['event_id'], (int)$link['member_id']);
        }
        return $this->transaction(function () use ($token, $user) {
            $link = $this->token($token, true);
            $member = (int)$link['member_id'];
            if ($link['purpose'] === 'UNSUBSCRIBE') {
                $this->disableInvitations($member);
                return 'unsubscribed';
            }
            $params = ['id'=>$member,'owner'=>$this->owner];
            if ($link['purpose'] === 'CLAIM') {
                if (!$this->matchingUser($user, $link['email']) || (int)$user->getId() !== (int)$link['target_user_id']
                    || ($link['user_id'] !== null && (int)$link['user_id'] !== (int)$user->getId())) {
                    throw new DomainException('Sign in to the VNV client account that requested this link, then open it again.');
                }
                $this->write('UPDATE vnv_break_members SET user_id=:user WHERE owner_id=:owner AND id=:id',
                    $params + ['user'=>(int)$user->getId()]);
            } else {
                $attach = $this->matchingUser($user, $link['email']) ? (int)$user->getId() : null;
                $this->write("UPDATE vnv_break_members SET status='ACTIVE', confirmed_at=UTC_TIMESTAMP(), unsubscribed_at=NULL,
                    user_id=COALESCE(user_id,:user) WHERE owner_id=:owner AND id=:id", $params + ['user'=>$attach]);
            }
            $this->write('UPDATE vnv_break_email_tokens SET used_at=UTC_TIMESTAMP() WHERE token_hash=:hash', ['hash'=>hash('sha256',$token)]);
            return $link['purpose'] === 'CLAIM' ? 'linked' : 'confirmed';
        });
    }

    public function unsubscribeMember(int $member): void
    {
        $this->transaction(fn() => $this->disableInvitations($member));
    }

    private function disableInvitations(int $member): void
    {
        $this->write("UPDATE vnv_break_members SET status='UNSUBSCRIBED',unsubscribed_at=UTC_TIMESTAMP()
            WHERE id=:id AND owner_id=:owner", ['id'=>$member,'owner'=>$this->owner]);
        $this->write("UPDATE vnv_break_email_tokens t JOIN vnv_break_members m ON m.id=t.member_id SET t.used_at=UTC_TIMESTAMP()
            WHERE m.id=:id AND m.owner_id=:owner AND t.purpose IN ('VERIFY','CLAIM','UNSUBSCRIBE') AND t.used_at IS NULL",
            ['id'=>$member,'owner'=>$this->owner]);
    }

    public function saveEvent(array $data): int
    {
        $eventId = (int)($data['event_id'] ?? 0);
        $title = self::field($data,'title',160);
        $location = self::field($data,'location',220);
        $status = self::field($data,'status',12);
        $price = self::field($data,'price',12);
        $when = self::field($data,'starts_at',24);
        $tz = new DateTimeZone('America/New_York');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$when,$tz);
        if (!$title || !$location || !in_array($status,['PUBLISHED','DRAFT'],true) || !$date || $date->format('Y-m-d\TH:i') !== $when
            || ($status === 'PUBLISHED' && $date <= new DateTimeImmutable('now',$tz))) {
            throw new DomainException('Enter an event title, location and valid upcoming Miami date and time.');
        }
        if (!preg_match('/^\d{1,5}(?:\.\d{1,2})?$/D',$price)) {
            throw new DomainException('Enter admission in USD, with at most two decimal places.');
        }
        $parts = explode('.', $price);
        $cents = (int)$parts[0] * 100 + (int)str_pad($parts[1] ?? '', 2, '0');
        if ($cents < 50) {
            throw new DomainException('Admission must be at least $0.50 per person.');
        }
        $params = ['owner'=>$this->owner,'title'=>$title,'location'=>$location,'starts'=>$date->format('Y-m-d H:i:s'),'price'=>$cents,'status'=>$status];
        return $this->transaction(function () use ($eventId,$params) {
            if (!$eventId) {
                $this->write('INSERT INTO vnv_break_events (owner_id,title,location,starts_at,price_cents,status)
                    VALUES (:owner,:title,:location,:starts,:price,:status)', $params);
                return (int)$this->db->lastId();
            }
            $event = $this->one('SELECT id FROM vnv_break_events WHERE id=:id AND owner_id=:owner FOR UPDATE',
                ['id'=>$eventId,'owner'=>$this->owner]);
            if (!$event) {
                throw new DomainException('Event not found.');
            }
            if ($this->one('SELECT id FROM vnv_break_registrations WHERE event_id=:id LIMIT 1', ['id'=>$eventId])) {
                throw new DomainException('Invitations have already been issued. Their date, location and ticket terms are locked.');
            }
            $this->write('UPDATE vnv_break_events SET title=:title,location=:location,starts_at=:starts,price_cents=:price,status=:status
                WHERE owner_id=:owner AND id=:id',$params + ['id'=>$eventId]);
            return $eventId;
        });
    }

    private static function nowMiami(): string
    {
        return (new DateTimeImmutable('now',new DateTimeZone('America/New_York')))->format('Y-m-d H:i:s');
    }

    public function invite(int $event, int $member): bool
    {
        return $this->transaction(function () use ($event,$member) {
            $show = $this->one("SELECT * FROM vnv_break_events WHERE id=:id AND owner_id=:owner AND status='PUBLISHED' FOR UPDATE",
                ['id'=>$event,'owner'=>$this->owner]);
            if (!$show || $show['starts_at'] <= self::nowMiami()) {
                throw new DomainException('Choose a published, upcoming event.');
            }
            $who = $this->one("SELECT * FROM vnv_break_members WHERE id=:id AND owner_id=:owner AND status='ACTIVE' FOR UPDATE",
                ['id'=>$member,'owner'=>$this->owner]);
            if (!$who) {
                throw new DomainException('Only confirmed club members can receive invitations.');
            }
            $this->write("INSERT INTO vnv_break_registrations (event_id,member_id,name,email,phone,companion_name,companion_email,party_size,unit_cents)
                SELECT :event,id,name,email,phone,companion_name,companion_email,party_size,:price FROM vnv_break_members WHERE id=:member
                ON DUPLICATE KEY UPDATE id=vnv_break_registrations.id", ['event'=>$event,'price'=>$show['price_cents'],'member'=>$member]);
            $reg = $this->one('SELECT id,invited_at FROM vnv_break_registrations WHERE event_id=:event AND member_id=:member FOR UPDATE',
                ['event'=>$event,'member'=>$member]);
            if ($reg['invited_at']) {
                return true;
            }
            $link = $this->issue($member,'INVITE',(int)$reg['id']);
            $stop = $this->issue($member,'UNSUBSCRIBE');
            $per = number_format($show['price_cents']/100,2);
            $this->mail($who['email'], 'Your Break Club invitation · ' . $show['title'],
                "Hi {$who['name']},\n\nYour priority invitation to {$show['title']} is ready.\n{$show['starts_at']} (Miami time) · {$show['location']}\nUSD $per per person · {$who['party_size']} in your party.\n\nView your invitation and purchase tickets:\n$link\n\nTo stop future club invitations:\n$stop");
            $this->write('UPDATE vnv_break_registrations SET invited_at=UTC_TIMESTAMP() WHERE id=:id', ['id'=>$reg['id']]);
            return true;
        });
    }

    private function registration(int $id, bool $lock = false): array
    {
        $r = $this->one('SELECT r.*,e.owner_id,e.title AS event_title,e.starts_at,e.location,e.status AS event_status,
            m.user_id,m.status AS member_status FROM vnv_break_registrations r JOIN vnv_break_events e ON e.id=r.event_id
            JOIN vnv_break_members m ON m.id=r.member_id AND m.owner_id=e.owner_id
            WHERE r.id=:id AND e.owner_id=:owner' . ($lock ? ' FOR UPDATE' : ''), ['id'=>$id,'owner'=>$this->owner]);
        if (!$r) {
            throw new DomainException('Invitation not found.');
        }
        return $r;
    }

    public function checkout(int $event, int $member): string
    {
        return $this->transaction(function () use ($event,$member) {
            $key = $this->one('SELECT id FROM vnv_break_registrations WHERE member_id=:member AND event_id=:event',
                ['member'=>$member,'event'=>$event]);
            $row = $this->registration((int)($key['id'] ?? 0),true);
            if (!$row['invited_at'] || $row['status'] !== 'INVITED' || $row['event_status'] !== 'PUBLISHED'
                || $row['member_status'] !== 'ACTIVE' || $row['starts_at'] <= self::nowMiami()) {
                throw new DomainException('Ticket checkout is unavailable for this invitation.');
            }
            $account = $row['stripe_account_id'] ?: $this->payments->account($this->owner);
            $attempt = (int)$row['checkout_attempt'];
            if ($row['checkout_session_id']) {
                $prior = $this->payments->session($row['checkout_session_id'],$account);
                if ($prior->status === 'open') {
                    return $prior->url;
                }
                if ($prior->status === 'complete') {
                    $this->applyPayment($row,$prior);
                    return SiteContext::publicBaseUrl() . '/api/public/break-club/receipt?session_id=' . rawurlencode($prior->id);
                }
                if ($prior->status !== 'expired') {
                    throw new DomainException('Please refresh the payment status before retrying.');
                }
                $attempt++;
            }
            $session = $this->payments->create($row,$account,$attempt);
            $this->write('UPDATE vnv_break_registrations SET stripe_account_id=:account,checkout_attempt=:attempt,
                checkout_session_id=:session WHERE id=:id', ['account'=>$account,'attempt'=>$attempt,'session'=>$session->id,'id'=>$row['id']]);
            return $session->url;
        });
    }

    private function applyPayment(array $row, object $session): void
    {
        if ($session->status !== 'complete' || $session->payment_status !== 'paid') {
            return;
        }
        $intent = $session->payment_intent;
        $charge = is_object($intent) ? $intent->latest_charge : null;
        $metadata = $session->metadata;
        $total = (int)$row['unit_cents'] * (int)$row['party_size'];
        if ($session->id !== $row['checkout_session_id'] || $session->mode !== 'payment' || strtolower($session->currency) !== 'usd'
            || (int)$session->amount_total !== $total || $metadata->vnv_program !== 'break_club'
            || (int)$metadata->break_owner_id !== $this->owner || (int)$metadata->break_registration_id !== (int)$row['id']
            || (int)$metadata->break_event_id !== (int)$row['event_id'] || !is_object($charge) || (int)$charge->amount_captured !== $total) {
            throw new DomainException('Payment verification needs review. No admission has been issued for this receipt.');
        }
        $net = $total - (int)$charge->amount_refunded;
        $state = $net === $total ? 'PAID' : ($net === 0 ? 'REFUNDED' : 'PARTIALLY_REFUNDED');
        $this->write('UPDATE vnv_break_registrations SET status=:status,paid_cents=:net,stripe_payment_intent_id=:intent,
            paid_at=COALESCE(paid_at,UTC_TIMESTAMP()) WHERE id=:id', ['status'=>$state,'net'=>$net,'intent'=>$intent->id,'id'=>$row['id']]);
    }

    public function reconcile(int $registration): void
    {
        $this->transaction(function () use ($registration) {
            $row = $this->registration($registration,true);
            if ($row['checkout_session_id']) {
                $this->applyPayment($row,$this->payments->session($row['checkout_session_id'],$row['stripe_account_id']));
            }
        });
    }

    public function receipt(string $sessionId): string
    {
        if (!preg_match('/^cs_[A-Za-z0-9_]{8,185}$/D',$sessionId)) {
            throw new DomainException('Invalid checkout receipt.');
        }
        $row = $this->one('SELECT r.id FROM vnv_break_registrations r JOIN vnv_break_events e ON e.id=r.event_id
            WHERE e.owner_id=:owner AND r.checkout_session_id=:session',['owner'=>$this->owner,'session'=>$sessionId]);
        if (!$row) {
            throw new DomainException('Checkout receipt not found.');
        }
        $this->reconcile((int)$row['id']);
        return $this->registration((int)$row['id'])['status'];
    }

    /** Webhooks provide an identifier only. Payment state always comes from the owner account at Stripe. */
    public function providerNotification(string $account, int $id): void
    {
        $row = $this->registration($id);
        if ($account !== '' && $account === $row['stripe_account_id']) {
            $this->reconcile($id);
        }
    }

    public function checkIn(int $registration, string $role, int $staff): void
    {
        $slots = ['PRINCIPAL'=>'principal','COMPANION'=>'companion'];
        if ($staff <= 0 || !isset($slots[$role])) {
            throw new DomainException('Choose the individual who is at the door.');
        }
        $slot = $slots[$role];
        $this->transaction(function () use ($registration,$role,$slot,$staff) {
            $row = $this->registration($registration,true);
            if ($row['status'] !== 'PAID' || ($role === 'COMPANION' && (int)$row['party_size'] !== 2)) {
                throw new DomainException('Check-in requires a paid ticket for this individual.');
            }
            $this->write("UPDATE vnv_break_registrations SET {$slot}_checked_at=UTC_TIMESTAMP(),{$slot}_checked_by=:staff
                WHERE id=:id AND {$slot}_checked_at IS NULL", ['staff'=>$staff,'id'=>$registration]);
        });
    }

    public function memberEvents(int $member): array
    {
        return $this->many("SELECT e.*,r.id AS registration_id,r.status AS registration_status,r.party_size,r.unit_cents,r.paid_cents,
            r.principal_checked_at,r.companion_checked_at FROM vnv_break_events e
            JOIN vnv_break_members m ON m.owner_id=e.owner_id AND m.id=:member
            LEFT JOIN vnv_break_registrations r ON r.member_id=m.id AND r.event_id=e.id
            WHERE e.owner_id=:owner AND e.status='PUBLISHED' AND ((m.status='ACTIVE' AND e.starts_at>=:now) OR r.status IN ('PAID','PARTIALLY_REFUNDED','REFUNDED'))
            ORDER BY e.starts_at",['member'=>$member,'owner'=>$this->owner,'now'=>self::nowMiami()]);
    }

    public function adminData(string $search = '', int $event = 0, int $page = 1): array
    {
        $params = ['owner'=>$this->owner];
        $filter = '';
        if (trim($search) !== '') {
            $value = '%' . strtr(mb_substr(trim($search),0,150),['!'=>'!!','%'=>'!%','_'=>'!_']) . '%';
            $fields = ['name','email','phone','companion_name','companion_email','category'];
            $terms = [];
            foreach ($fields as $field) {
                $terms[] = "m.$field LIKE :$field ESCAPE '!'";
                $params[$field] = $value;
            }
            $filter = ' AND (' . implode(' OR ',$terms) . ')';
        }
        $base = " FROM vnv_break_members m WHERE m.owner_id=:owner $filter";
        $total = (int)$this->one('SELECT COUNT(*) AS n' . $base,$params)['n'];
        $page = max(1,min($page,(int)ceil($total/50)));
        $members = $this->many('SELECT m.*' . $base . ' ORDER BY m.created_at DESC,m.id DESC LIMIT 50 OFFSET ' . (($page-1)*50),$params);
        $events = $this->many('SELECT * FROM vnv_break_events WHERE owner_id=:owner ORDER BY starts_at DESC,id DESC',['owner'=>$this->owner]);
        $registrations = [];
        $report = ['invited_requests'=>0,'paid_requests'=>0,'paid_people'=>0,'paid_cents'=>0,'checked_in'=>0];
        if ($event) {
            $join = ' FROM vnv_break_registrations r JOIN vnv_break_events e ON e.id=r.event_id JOIN vnv_break_members m
                ON m.id=r.member_id AND m.owner_id=e.owner_id WHERE e.owner_id=:owner AND e.id=:event';
            $registrations = $this->many('SELECT r.*,e.title AS event_title,m.user_id' . $join . $filter .
                ' ORDER BY r.name,r.id', $params + ['event'=>$event]);
            $report = array_map('intval',$this->one("SELECT COUNT(r.invited_at) AS invited_requests, SUM(r.status='PAID') AS paid_requests,
                SUM(IF(r.status='PAID',r.party_size,0)) AS paid_people, SUM(r.paid_cents) AS paid_cents,
                SUM((r.principal_checked_at IS NOT NULL)+(r.companion_checked_at IS NOT NULL)) AS checked_in" . $join,
                ['owner'=>$this->owner,'event'=>$event]));
        }
        return ['members'=>$members,'events'=>$events,'registrations'=>$registrations,'member_total'=>$total,'page'=>$page,'report'=>$report];
    }
}
