<?php
declare(strict_types=1);

use App\Entity\User;
use App\Repositories\Connection;
use App\Services\BreakClubCheckout;
use App\Services\BreakClubService;

// Run only against a disposable MySQL/MariaDB schema containing "break_test" in its name.
$dsn = getenv('VNV_BREAK_TEST_DSN');
if (!$dsn || !preg_match('/(?:^|[;:])dbname=([a-zA-Z0-9_]*break_test[a-zA-Z0-9_]*)(?:;|$)/', $dsn)) {
    fwrite(STDERR, "Set VNV_BREAK_TEST_DSN to a disposable break_test database.\n");
    exit(2);
}
spl_autoload_register(static function (string $name): void {
    if (str_starts_with($name,'App\\')) {
        require dirname(__DIR__) . '/src/' . str_replace('\\','/', substr($name,4)) . '.php';
    }
});
$_ENV['DATABASE_URL'] = $dsn;
$_ENV['SITE_KEY'] = 'vnvevents';
$_ENV['SITE_BUSINESS_USER_ID'] = '2';
$_ENV['SITE_PUBLIC_BASE_URL'] = 'https://qa.invalid';
$_SESSION = [];
$pdo = new PDO($dsn);
$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$schema = file_get_contents(dirname(__DIR__) . '/db/20261125_vnv_break_club.sql');
$pdo->exec($schema);
$pdo->exec($schema);
$rows = $pdo->query('SELECT COUNT(*) FROM vnv_break_members')->fetchColumn();
if ((int)$rows !== 0) { throw new RuntimeException('Use an empty test schema.'); }

function check(bool $value, string $description): void {
    if (!$value) {throw new RuntimeException($description);}
    echo "PASS $description\n";
}
function rejected(callable $fn): bool {
    try {$fn();} catch (DomainException $e) {return true;}
    return false;
}
function linkFrom(string $body, int $position = 0): string {
    preg_match_all('/token=([a-f0-9]{64})/',$body,$matches);
    return $matches[1][$position] ?? throw new RuntimeException('Missing email link');
}
function client(int $id,string $email,int $owner=2): User {
    return new User($id,'Alex','Tester',$email,'unused',null,1,null,5,null,$owner,[],0,'FREE','1');
}
class ClubTestCheckout extends BreakClubCheckout {
    public array $saved=[];
    public int $creates=0;
    public function account(int $owner): string {return 'acct_club_test';}
    public function create(array $r,string $account,int $attempt): object {
        $this->creates++;
        $id = 'cs_test_club_' . $r['id'] . '_' . $attempt;
        return $this->saved[$id] = (object)[
            'id'=>$id,'url'=>'https://checkout.stripe.test/'.$id,'mode'=>'payment','status'=>'open','payment_status'=>'unpaid',
            'currency'=>'usd','amount_total'=>$r['party_size']*$r['unit_cents'],
            'metadata'=>(object)['vnv_program'=>'break_club','break_registration_id'=>$r['id'],'break_event_id'=>$r['event_id'],'break_owner_id'=>$r['owner_id']],
            'payment_intent'=>(object)['id'=>'pi_club_test_'.$r['id'],'latest_charge'=>(object)['amount_captured'=>$r['unit_cents']*$r['party_size'],'amount_refunded'=>0]]
        ];
    }
    public function session(string $id,string $account): object {
        if ($account !== 'acct_club_test') {throw new RuntimeException('Wrong merchant');}
        return $this->saved[$id];
    }
}
$mail=[];
$failMail=false;
$gateway=new ClubTestCheckout();
$service=new BreakClubService(new Connection(),$gateway,static function($email,$subject,$body) use (&$mail,&$failMail) {
    if ($failMail) {return false;}
    $mail[]=['to'=>$email,'body'=>$body];
    return true;
});
$base=['name'=>'Alex Tester','email'=>'Alex@Example.test','phone'=>'(305) 555-0120','consent'=>'1',
    'category'=>'COFFEE','party_size'=>'2','companion_name'=>'Mia Tester','companion_email'=>'guest@example.test',
    'utm_source'=>'instagram','utm_campaign'=>'first-coffee','utm_content'=>'post-1'];
check(rejected(fn()=>BreakClubService::applicant(array_replace($base,['party_size'=>'3']))),'three attendees rejected');
check(rejected(fn()=>BreakClubService::applicant(array_replace($base,['party_size'=>'2','companion_name'=>'']))),'second attendee must have a name');
check(rejected(fn()=>BreakClubService::applicant(array_replace($base,['consent'=>'0']))),'marketing consent required');
check(rejected(fn()=>BreakClubService::requireCsrf('wrong')),'invalid form token rejected');
BreakClubService::requireCsrf(BreakClubService::csrfToken());
$failMail=true;
try {$service->join($base);} catch (RuntimeException $e) {}
check($pdo->query('SELECT status FROM vnv_break_members')->fetchColumn() === 'PENDING'
    && (int)$pdo->query('SELECT COUNT(*) FROM vnv_break_email_tokens')->fetchColumn() === 0,
    'mail outage preserves pending signup and confirmation can be retried');
$failMail=false;
$service->join($base);
$service->join(array_replace($base,['name'=>'Wrong name','utm_source'=>'should-not-overwrite']));
$first=$service->adminData()['members'][0];
check(count($mail) === 1 && $first['status']==='PENDING' && $first['utm_source'] === 'instagram' && $first['name']==='Alex Tester','duplicate request preserves first signup and verified ownership');
$member=(int)$first['id'];
$token=linkFrom($mail[0]['body']);
check($service->linkDetails($token)['purpose']==='VERIFY' && $service->adminData()['members'][0]['status']==='PENDING','email scanner GET cannot subscribe');
check($service->useLink($token,null)==='confirmed' && rejected(fn()=>$service->useLink($token,null)),'one-time email confirmation');
$user=client(45,'alex@example.test');
check(!BreakClubService::hasVerifiedLogin($user) && $service->memberForUser($user)===null,'unverified restored session cannot read private club');
$service->claimForUser($user);
check(count($mail)===1,'weak cookie identity cannot initiate private account link');
$_SESSION['vnv_credentials_verified_for']=45;
$service->claimForUser($user);
$claim=linkFrom(end($mail)['body']);
check(rejected(fn()=>$service->useLink($claim,client(46,'alex@example.test'))),'claim cannot bind another account');
$service->useLink($claim,$user);
check((int)$service->memberForUser($user)['id']===$member && $service->memberForUser(client(45,'alex@example.test',28))===null,'inbox proof binds right user and tenant');
$date=(new DateTimeImmutable('tomorrow',new DateTimeZone('America/New_York')))->format('Y-m-d').'T09:15';
$event=$service->saveEvent(['title'=>'Private QA morning','location'=>'QA site','price'=>'24.50','starts_at'=>$date,'status'=>'PUBLISHED']);
check(count($service->memberEvents($member))===1 && rejected(fn()=>$service->checkout($event,$member)),'upcoming visible independently from entitlement to checkout');
$failMail=true;
try {$service->invite($event,$member);} catch (RuntimeException $e) {}
check(!$service->adminData('',$event)['registrations'],'failed invitation cannot appear delivered');
$failMail=false;
$service->invite($event,$member);
$service->invite($event,$member);
check(count($mail)===3,'invitation retry does not email twice');
$invite=linkFrom(end($mail)['body']);
$unsubscribe=linkFrom(end($mail)['body'],1);
check($service->linkDetails($invite)['total_cents']===4900,'signed invitation quotes exactly two individual admissions');
$url=$service->useLink($invite,null);
check($service->checkout($event,$member)===$url && $gateway->creates===1,'repeated pay clicks reuse the same hosted checkout');
$reg=$service->adminData('',$event)['registrations'][0];
$id=(int)$reg['id'];
$session=$gateway->session($reg['checkout_session_id'],'acct_club_test');
check($service->receipt($session->id)==='INVITED' && rejected(fn()=>$service->checkIn($id,'COMPANION',2)),'checkout creation and unpaid returns do not admit');
$session->status='expired';
$second=$service->checkout($event,$member);
check($second!==$url && $gateway->creates===2,'expired checkout replaced exactly once');
$reg=$service->adminData('',$event)['registrations'][0];
$session=$gateway->session($reg['checkout_session_id'],'acct_club_test');
$session->status='complete';
$session->payment_status='paid';
$session->amount_total=1;
check(rejected(fn()=>$service->receipt($session->id)),'amount mismatch cannot issue tickets');
$session->amount_total=4900;
$service->providerNotification('acct_somewhere_else',$id);
check($service->adminData('',$event)['registrations'][0]['status']==='INVITED','different merchant notification cannot issue tickets');
$service->providerNotification('acct_club_test',$id);
check($service->receipt($session->id)==='PAID' && $service->adminData('',$event)['report']['paid_people']===2,'independent verified settlement reconciled exactly once');
$service->checkIn($id,'COMPANION',2);
$service->checkIn($id,'COMPANION',2);
$report=$service->adminData('guest@example.test',$event);
check(count($report['registrations'])===1 && $report['report']['checked_in']===1 && $report['registrations'][0]['principal_checked_at']===null,'companion arrives alone and duplicate check-in is harmless');
check($service->adminData('no match',$event)['report']['paid_cents']===4900,'search does not change gross event report');
$service->useLink($unsubscribe,null);
check($service->memberForUser($user)['status']==='UNSUBSCRIBED' && count($service->memberEvents($member))===1,'unsubscribe stops invites while preserving paid admission');
$session->payment_intent->latest_charge->amount_refunded=2450;
$service->reconcile($id);
check($service->receipt($session->id)==='PARTIALLY_REFUNDED' && $service->adminData('',$event)['report']['paid_cents']===2450,'partial refunds reported and held for review');
$session->payment_intent->latest_charge->amount_refunded=4900;
$service->reconcile($id);
check($service->receipt($session->id)==='REFUNDED' && rejected(fn()=>$service->checkIn($id,'PRINCIPAL',2)),'refunded ticket blocks admission');
$_ENV['SITE_BUSINESS_USER_ID']='28';
$other=new BreakClubService(new Connection(),$gateway);
check(count($other->adminData()['members'])===0 && rejected(fn()=>$other->reconcile($id)), 'tenant cannot inspect another owner payment');
$_ENV['SITE_KEY']='other-brand';
check(rejected(fn()=>new BreakClubService()), 'other brands cannot access VNV club');
