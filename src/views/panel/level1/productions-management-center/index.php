<?php

use App\Services\LoginService;
use App\Services\ProductionsAccountsBridge;
use App\Services\ProductionsCenterAccess;
use App\Services\ProductionsWorkboard;
use App\Utils\LocationUtils;
use App\Utils\SiteContext;
use App\Utils\TemplateResponse;

header('Cache-Control: private, no-store');
header('Referrer-Policy: same-origin');
$user = LoginService::getSession();
if (!$user) {
    LocationUtils::redirectInternal('login');
}
$db = new PDO($_ENV['DATABASE_URL'], null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec("SET NAMES utf8mb4");
$access = new ProductionsCenterAccess($db, SiteContext::businessOwnerId(), SiteContext::siteKey());
try {
    $actor = $access->actor((int)$user->getId(), (int)$user->getLevel(),
        (int)($_SESSION['vnv_credentials_verified_for'] ?? 0), (int)($_SESSION['current_institution_id'] ?? 0));
} catch (DomainException $e) {
    http_response_code(403);
    echo htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
    if (!\App\Services\BreakClubService::hasVerifiedLogin($user)) {
        $href = htmlspecialchars(LocationUtils::pathFor('logout'), ENT_QUOTES, 'UTF-8');
        echo ' <a href="'.$href.'">Sign out and sign in again</a>';
    }
    return;
}
$direction = $actor['level'] === 1;
$tabs = ['overview'=>'Overview','crm'=>'Corporate CRM','active'=>'Active productions','past'=>'Past productions',
    'originals'=>'Original experiences','tasks'=>'Tasks & priorities','accounts'=>'Software accounts',
    'demo'=>'Demo center','onboarding'=>'Onboarding & training','reports'=>'Reports'];
if (!$actor['crm']) {
    unset($tabs['crm']);
}
if ($direction) {
    $tabs['access'] = 'Team access';
}
$method = $_SERVER['REQUEST_METHOD'];
$tab = $method === 'POST' ? ($_POST['tab'] ?? $_GET['tab'] ?? 'overview') : ($_GET['tab'] ?? 'overview');
if (!is_string($tab) || !isset($tabs[$tab])) {
    http_response_code(403);
    echo 'This section is unavailable for your account.';
    return;
}
$route = LocationUtils::pathFor('panel/productions-management-center');
$sessionKey = $actor['owner_id'].'/'.$actor['user_id'];
$token = $_SESSION['productions_csrf'][$sessionKey] ??= bin2hex(random_bytes(32));
$work = new ProductionsWorkboard($db, $actor['owner_id'], $actor['site_key'], $actor['user_id'], $direction, $actor['crm']);
$bridge = new ProductionsAccountsBridge();
if ($method === 'POST') {
    $posted = $_POST['_csrf'] ?? $_POST['csrf_token'] ?? $_POST['csrf'] ?? null;
    if (!is_string($posted) || !hash_equals($token, $posted)) {
        http_response_code(403);
        echo 'Reload this page before making changes.';
        return;
    }
    $action = $_POST['action'] ?? '';
    if (!is_string($action) || !preg_match('/^(work_[a-z0-9_]+|account\.[a-z]+|delegation\.save)$/D', $action)
        || (str_starts_with($action,'work_crm_') && !$actor['crm'])
        || ($action === 'delegation.save' && !$direction)) {
        http_response_code(403);
        echo 'This action is unavailable for your account.';
        return;
    }
    $auditId = null;
    try {
        if ($action === 'delegation.save') {
            $access->setGrants($actor, (int)($_POST['staff_id'] ?? 0), (array)($_POST['grants'] ?? []));
            $result = ['message'=>'Team access saved.'];
        } else {
            $target = (int)($_POST['account_id'] ?? $_POST['project_id'] ?? $_POST['task_id'] ?? 0);
            $auditId = $access->audit($actor,$action,'started',$target ?: null);
            if (str_starts_with($action, 'account.')) {
                $result = $bridge->act($actor,$action,$_POST);
            } else {
                $work->act($action,$_POST);
                $result = ['message'=>'Changes saved.'];
            }
            $access->finishAudit($auditId,'completed');
        }
        $_SESSION['productions_notice'][$sessionKey] = [
            'type'=>'success','message'=>$result['message'] ?? 'Changes saved.',
            'invitation_code'=>$result['invitation_code'] ?? null,
        ];
        $accountId = (int)($result['account_id'] ?? $_POST['account_id'] ?? 0);
    } catch (Throwable $e) {
        if ($auditId) {
            $access->finishAudit($auditId,'failed');
        }
        $expected = $e instanceof DomainException || $e instanceof InvalidArgumentException;
        if (!$expected) {
            error_log('[Productions center] Action '.$action.' failed: '.get_class($e));
        }
        $_SESSION['productions_notice'][$sessionKey] = ['type'=>'danger',
            'message'=>$expected ? $e->getMessage() : 'The change could not be confirmed. Reopen the record before retrying.'];
    }
    $query = ['tab'=>$tab];
    foreach (['project_id','opportunity_id'] as $field) {
        if (isset($_POST[$field]) && filter_var($_POST[$field],FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])) {
            $query[$field === 'project_id' ? 'project' : 'opportunity'] = (int)$_POST[$field];
        }
    }
    if (!empty($accountId) && in_array($tab,['accounts','demo','onboarding'],true)) {
        $query['account_id'] = $accountId;
    }
    header('Location: '.$route.'?'.http_build_query($query),true,303);
    return;
}
if ($method !== 'GET') {
    header('Allow: GET, POST');
    http_response_code(405);
    return;
}
$notice = $_SESSION['productions_notice'][$sessionKey] ?? null;
unset($_SESSION['productions_notice'][$sessionKey]);
$context = ['tabs'=>$tabs,'tab'=>$tab,'pmc_tab'=>$tab,'pmc_url'=>$route,'actor'=>$actor,
    'pmc_crm_url'=>$actor['crm'] ? LocationUtils::pathFor('panel/planner-hub/management/crm/partnerships') : '',
    'csrf_token'=>$token,'pmc_csrf'=>$token,'notice'=>$notice,'direction'=>$direction];
try {
    if (in_array($tab,['accounts','demo','onboarding'],true)) {
        $context['account_data'] = $context['accounts'] = $bridge->view($actor,$tab,$_GET);
    } elseif ($tab === 'access') {
        $context['staff'] = $access->staff();
        $context['selected_staff'] = (int)($_GET['staff'] ?? 0);
        $context['grant_labels'] = ProductionsCenterAccess::GRANTS;
    } else {
        $context['workboard'] = $work->view($tab,$_GET);
        if (in_array($tab,['overview','reports'],true)) {
            $context['portfolio'] = $bridge->summary($actor);
        }
    }
} catch (DomainException $e) {
    http_response_code(403);
    $context['load_error'] = 'This record is unavailable for your account.';
} catch (Throwable $e) {
    http_response_code(503);
    error_log('[Productions center] Unable to open '.$tab.': '.get_class($e));
    $context['load_error'] = 'This section could not load its records. Please reopen it before making changes.';
}
echo TemplateResponse::render(__DIR__.'/index.twig',$context);
