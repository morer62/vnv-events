<?php
declare(strict_types=1);

use App\Services\ProductionsCenterAccess;
use App\Utils\SiteContext;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__).'/vendor/autoload.php';
Dotenv\Dotenv::createImmutable(dirname(__DIR__))->load();
$options = getopt('', ['staff:','expected-name:']);
$id = (int)($options['staff'] ?? 0);
$expected = trim((string)($options['expected-name'] ?? ''));
$owner = SiteContext::businessOwnerId();
if (SiteContext::siteKey() !== 'vnvevents' || $id < 1 || $expected === '') {
    throw new RuntimeException('Provide --staff and --expected-name in the VNV environment.');
}
$db = new PDO($_ENV['DATABASE_URL'],null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec('SET NAMES utf8mb4');
$db->beginTransaction();
try {
    $q = $db->prepare("SELECT CONCAT(TRIM(name),' ',TRIM(lastname)) FROM users
        WHERE id=? AND id_owner=? AND level=4 AND is_active=1 FOR UPDATE");
    $q->execute([$id,$owner]);
    if ($q->fetchColumn() !== $expected) {
        throw new RuntimeException('The staff record did not match the expected active VNV team member.');
    }
    $q = $db->prepare("SELECT id FROM productions_center_audit WHERE id_owner=? AND site_key='vnvevents'
        AND action='rollout.assign' AND result='completed' AND target_id=? LIMIT 1");
    $q->execute([$owner,$id]);
    if ($q->fetchColumn()) {
        $db->commit();
        echo "Assignment already recorded. Current permissions and progress were preserved.\n";
        exit;
    }
    $q = $db->prepare('SELECT feature_slug FROM user_feature_permissions WHERE id_user=?');
    $q->execute([$id]);
    $before = $q->fetchAll(PDO::FETCH_COLUMN);
    $q = $db->prepare('INSERT INTO user_feature_permissions (id_user,feature_slug) VALUES (?,?)');
    foreach (array_diff(['crm_sponsors','pmc_center','pmc_provision','pmc_invite','pmc_reset'],$before) as $slug) {
        $q->execute([$id,$slug]);
    }
    $task = 'Review Corporate CRM, create your training organization, then complete the owner, team and customer journeys.';
    $acceptance = 'Review assigned opportunities, categories, history and follow-ups. In Demo Center create your training organization, use fictional contacts and test the enabled modules as Levels 2, 4 and 5 with separate sign-ins. In Onboarding & Training, print the role checklists, record missing modules or navigation issues and have direction review your evidence before the first client demo.';
    $q = $db->prepare("SELECT id FROM orders_team_tasks WHERE id_owner=? AND id_user=? AND pmc_task_key='PMC_TEAM_TRAINING' AND pmc_project_id IS NULL");
    $q->execute([$owner,$id]);
    $taskId = $q->fetchColumn();
    if (!$taskId) {
        $q = $db->prepare("INSERT INTO orders_team_tasks(id_order,id_user,id_owner,id_service,task_description,is_done,
            pmc_task_key,pmc_phase,pmc_priority,pmc_acceptance) VALUES(NULL,?,?,0,?,0,'PMC_TEAM_TRAINING','Discover','normal',?)");
        $q->execute([$id,$owner,$task,$acceptance]);
        $taskId = $db->lastInsertId();
    }
    (new ProductionsCenterAccess($db,$owner,SiteContext::siteKey()))->audit(['user_id'=>0],
        'rollout.assign','completed',$id,'Authorized CLI rollout. Existing grants preserved. Training task #'.$taskId);
    $db->commit();
    echo 'Training task #'.$taskId." is assigned.\n";
} catch (Throwable $error) {
    $db->rollBack();
    throw $error;
}
