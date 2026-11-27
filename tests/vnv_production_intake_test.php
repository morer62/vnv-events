<?php
// Exercise the existing entry point without sending mail or creating live contacts.
namespace {
    class Records { public static array $request = [], $lead = [], $deals = [], $mail = []; }
    class Redirect extends \RuntimeException {}
    function expect($condition, string $message): void { if (!$condition) throw new \RuntimeException($message); }
}
namespace App\Utils {
    class Router {
        public static $submit;
        public function post($fn) { self::$submit = $fn; }
        public function run() {}
    }
    class LocationUtils {
        public static function pathFor($path) { return '/' . $path; }
        public static function redirectTo($path) { throw new \Redirect($path); }
        public static function redirectInternal($path) { throw new \Redirect($path); }
    }
    class MessageUtil { public static function setMessage(...$args) {} }
}
namespace App\Services {
    class LoginService { public static function getSession() { return null; } }
    class EmailService {
        public function __construct($owner) {}
        public function sendBulkEmail($recipients, $subject, $body) {
            \Records::$mail[] = [$recipients, $subject, $body];
            return array_fill_keys($recipients, true);
        }
    }
}
namespace App\Repositories {
    class EventRequestRepository { public function createFromPublicForm($data) { \Records::$request = $data; return 123; } }
    class LeadIntakeRepository { public function upsert($owner, $data) { \Records::$lead = $data; } }
    class CommercialPartnershipRepository {
        public function createPublicOpportunity($owner, $site, $program, $data) { \Records::$deals[] = [$program, $data]; }
    }
}
namespace {
    $base = dirname(__DIR__);
    require $base . '/src/Services/VnvCommercialIntake.php';
    require $base . '/src/Services/PublicRequestNotification.php';
    require $base . '/src/views/api/public/event-request/index.php';
    $_SERVER['HTTP_HOST'] = 'localhost';
    $_ENV['VNV_EVENTS_OWNER_ID'] = 2;
    $common = ['event_address'=>'Test venue','event_date'=>'2027-03-02','event_time'=>'18:30',
        'full_name'=>'Example Contact','email'=>'example@example.invalid','phone'=>'3055550100','guest_count'=>75,
        'contact_consent'=>'1','details'=>'Please contact me','organization_name'=>'Sample Organization',
        'budget_range'=>'$12,000','project_event_type'=>'Reception','form_source'=>'production_store'];
    $cases = [
        [['Appetizers','Chairs'],null],
        [['Event Planner','Event Coordinator'],null],
        [['Full Event Production','Appetizers'],'CORPORATE'],
        [['Brand Activation'],'ACTIVATION'],
        [['Sponsorship Opportunities'],'CORPORATE'],
        [['Nonprofit Partnerships'],'NONPROFIT'],
        [['Event Software Demo'],'CORPORATE'],
    ];
    foreach ($cases as [$categories, $program]) {
        Records::$deals = Records::$mail = [];
        $_POST = $common + ['selected_services'=>json_encode($categories)];
        try { (\App\Utils\Router::$submit)(); throw new \RuntimeException('No redirect'); }
        catch (Redirect $r) { expect(str_contains($r->getMessage(), '/sucess-request?lead=success&lead_id=123'), 'Wrong success redirect'); }
        expect(json_decode(Records::$request['selected_services'],true) === $categories, 'Selected services changed');
        expect(Records::$lead['external_id'] === 'event-request-123', 'Missing CRM lead');
        expect(Records::$lead['contact_name'] === $_POST['full_name'], 'Missing contact');
        expect(count(Records::$mail) === 1 && count(Records::$mail[0][0]) === 3, 'Missing existing notification');
        expect(count(Records::$deals) === (int)($program !== null), 'Unexpected secondary pipeline');
        if ($program) {
            expect(Records::$deals[0][0] === $program && Records::$deals[0][1]['organization_name'] === 'Sample Organization','Wrong partner routing');
            expect(str_contains(Records::$mail[0][2], 'Reception') && str_contains(Records::$request['details'], '$12,000'), 'Missing commercial context');
        } else expect(Records::$request['details'] === 'Please contact me', 'Corporate fields leaked into traditional request');
    }
    $_POST['contact_consent'] = '';
    Records::$request = Records::$mail = [];
    try { (\App\Utils\Router::$submit)(); } catch (Redirect $r) { expect($r->getMessage() === '/', 'Consent error route'); }
    expect(!Records::$request && !Records::$mail, 'Unconsented request saved');
    echo "Seven request scenarios and missing consent passed. No external mail or live records.\n";
}
