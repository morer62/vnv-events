<?php
declare(strict_types=1);

// Run against a new empty disposable local MariaDB schema. No payment gateway or notification is invoked.
use App\Repositories\PayrollTimeLogsRepository;
use App\Repositories\PayrollPaymentsRepository;
use App\Services\TeamMemberContractService;

$dsn=getenv('VNV_PAYROLL_TEST_DSN') ?: '';
if (!preg_match('/^mysql:host=127\.0\.0\.1;port=\d+;dbname=pmc_payroll_test_[a-z0-9_]+;/D',$dsn)) {
    throw new RuntimeException('Use an empty local pmc_payroll_test_ schema.');
}
$root=getenv('VNV_QA_SOURCE_ROOT') ?: dirname(__DIR__);
$autoload=require $root.'/vendor/autoload.php';
$autoload->addPsr4('App\\',$root.'/src',true);
$_ENV['DATABASE_URL']=$dsn;
$pdo=new PDO($dsn,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
if ($pdo->query('SHOW TABLES')->fetchColumn()!==false) { throw new RuntimeException('This test requires an empty schema.'); }
$pdo->exec(file_get_contents($root.'/db/team_member_contracts_required.sql'));
$pdo->exec('CREATE TABLE users (id INT PRIMARY KEY,email VARCHAR(150),level INT)');
$pdo->exec('CREATE TABLE payroll_hours (id INT AUTO_INCREMENT PRIMARY KEY,id_user INT,id_owner INT,start_time DATETIME,end_time DATETIME NULL,is_paid TINYINT DEFAULT 0,location_lat VARCHAR(40),location_long VARCHAR(40),notes TEXT,paid_at DATETIME NULL,proof_url TEXT) ENGINE=InnoDB');
$pdo->exec('CREATE TABLE payroll_payments (id INT AUTO_INCREMENT PRIMARY KEY,id_user INT,id_owner INT,hours_count INT,hours_ids TEXT,proof_url TEXT,paid_at DATETIME,hourly_rate_snapshot DECIMAL(10,2),aditional_info TEXT) ENGINE=InnoDB');
$pdo->exec("INSERT INTO users VALUES(101,'crew@example.invalid',4),(102,'other@example.invalid',4)");
$contract=new TeamMemberContractService(); $clock=new PayrollTimeLogsRepository(); $pay=new PayrollPaymentsRepository();
$checks=0;
$check=static function(bool $ok,string $desc) use (&$checks) { if (!$ok) { throw new RuntimeException($desc); } $checks++; };
$check(!$contract->isClockInAllowed(101,8),'Missing staff contract must block clock-in');
$pdo->exec("INSERT INTO team_member_contracts(id_owner,team_member_id,status) VALUES(8,101,'PENDING')");
$check(!$contract->isClockInAllowed(101,8),'Pending contract must block clock-in');
$pdo->exec("UPDATE team_member_contracts SET status='SIGNED',signed_at=NOW() WHERE id_owner=8 AND team_member_id=101");
$check($contract->isClockInAllowed(101,8) && !$contract->isClockInAllowed(101,9),'Signed status only applies in its own organization');
$clock->startNow(101,8);
$logs=$clock->getActiveLogsByUserAndOwner(101,8);
$check(count($logs)===1 && count($clock->getActiveLogsByUserAndOwner(101,9))===0 && count($clock->getActiveLogsByUserAndOwner(102,8))===0,'Open shift limited to signed-in staff and owner');
$id=(int)$logs[0]->id;
usleep(1100000); // The payroll repository correctly rejects a zero-duration shift.
$clock->stopNow($id,null,null,'Arrival check complete');
$check(!$clock->getActiveLogsByUserAndOwner(101,8) && count($clock->getRecentLogsByUserAndOwner(101,8))===1,'End shift and view own attendance history');
$clock->startNow(102,8); $other=(int)$clock->getActiveLogsByUserAndOwner(102,8)[0]->id; $clock->stopNow($other);
$clock->startNow(101,9); $outside=(int)$clock->getActiveLogsByUserAndOwner(101,9)[0]->id; $clock->stopNow($outside);
$clock->startNow(101,8); $open=(int)$clock->getActiveLogsByUserAndOwner(101,8)[0]->id;
$payRows=static fn(array $ids)=>$pay->recordSelectedPayment(101,8,$ids,'qa-receipt',25,'fixture',gmdate('Y-m-d H:i:s'));
foreach ([$outside,$other,$open] as $invalid) {
    try { $payRows([$id,$invalid]); throw new LogicException('Invalid selection should have failed'); }
    catch (RuntimeException $error) { $check((int)$pdo->query('SELECT COUNT(*) FROM payroll_payments')->fetchColumn()===0,'Partial payroll rolled back'); }
}
$check(count($payRows([$id,$id]))===1,'One payment for one selected completed shift');
$check((int)$pdo->query('SELECT COUNT(*) FROM payroll_hours WHERE is_paid=1')->fetchColumn()===1,'Other owners and crew retain their own balances');
try { $payRows([$id]); throw new LogicException('Double payment should have failed'); }
catch (RuntimeException $error) { $check((int)$pdo->query('SELECT COUNT(*) FROM payroll_payments')->fetchColumn()===1,'Repeat payment rejected'); }
echo "Native attendance/payroll checks passed: $checks.\n";
