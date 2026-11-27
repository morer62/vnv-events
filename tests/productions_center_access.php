<?php
declare(strict_types=1);

// Local synthetic MariaDB integration. All fixture tables shadow existing names in this connection.
require __DIR__.'/../src/Services/ProductionsCenterAccess.php';
use App\Services\ProductionsCenterAccess;

$dsn = getenv('PMC_TEST_DSN');
if (!$dsn || !str_starts_with($dsn,'mysql:host=127.0.0.1;')) {
    throw new RuntimeException('Supply a loopback PMC_TEST_DSN for the isolated integration test.');
}
$db = new PDO($dsn, 'root', '', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec('CREATE TEMPORARY TABLE users (id INT PRIMARY KEY,level INT,id_owner INT,is_active INT,name VARCHAR(60),lastname VARCHAR(60))');
$db->exec('CREATE TEMPORARY TABLE user_feature_permissions(id_user INT, feature_slug VARCHAR(90), UNIQUE(id_user,feature_slug))');
$db->exec('CREATE TEMPORARY TABLE institution_profile(id INT PRIMARY KEY,id_owner INT)');
$db->exec(str_replace('CREATE TABLE IF NOT EXISTS','CREATE TEMPORARY TABLE',file_get_contents(__DIR__.'/../db/sql/productions-center-audit.sql')));
$db->exec("INSERT INTO users VALUES (2,1,2,1,'Demo','Direction'),(8,4,2,1,'Test','Producer'),(9,4,99,1,'Another','Org'),(10,5,2,1,'Test','Customer'),(11,4,2,0,'Inactive','User'),(12,1,2,1,'Other','Admin')");
$db->exec('INSERT INTO institution_profile VALUES (42,2),(99,99)');
$db->exec("INSERT INTO user_feature_permissions VALUES (8,'crm_sponsors'),(9,'pmc_center'),(10,'pmc_center'),(11,'pmc_center')");
$access = new ProductionsCenterAccess($db,2,'vnvevents');
function denied(callable $fn): void {
    try { $fn(); } catch (DomainException) { return; }
    throw new RuntimeException('An unentitled operation was accepted.');
}
function check(bool $ok,string $name): void { if (!$ok) { throw new RuntimeException($name); } }
$owner=$access->actor(2,1,2);
foreach ([[8,4,8],[9,4,9],[10,5,10],[11,4,11],[12,1,12],[2,4,2],[2,1,8]] as $case) {
    denied(fn()=>$access->actor(...$case));
}
denied(fn()=>(new ProductionsCenterAccess($db,2,'other'))->actor(2,1,2));
$access->setGrants($owner,8,['pmc_center','pmc_provision']);
$staff=$access->actor(8,4,8,42);
check($staff['crm'] && array_keys($staff['managers']) === [8], 'Scoped managers / existing CRM');
check(in_array('accounts.create',$staff['capabilities'],true) && !in_array('accounts.lifecycle',$staff['capabilities'],true),'Delegated limits');
denied(fn()=>$access->actor(8,4,8,99));
denied(fn()=>$access->setGrants($staff,8,['pmc_lifecycle']));
denied(fn()=>$access->setGrants($owner,9,['pmc_center']));
$access->setGrants($owner,8,[]);
denied(fn()=>$access->actor(8,4,8));
check((int)$db->query("SELECT COUNT(*) FROM user_feature_permissions WHERE id_user=8 AND feature_slug='crm_sponsors'")->fetchColumn()===1,'Existing grant retained');
check((int)$db->query("SELECT COUNT(*) FROM productions_center_audit WHERE result='completed'")->fetchColumn()===2,'Audit');
echo "Passed: site, owner, institution, verified identity, level, scope, grant, revocation and audit checks.\n";
