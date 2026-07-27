<?php

use App\Repositories\AiAgentsRepository;
use App\Repositories\AiAgentConnectionsRepository;
use App\Repositories\AiProviderConnectionsRepository;
use App\Repositories\Connection;
use App\Services\AiAgentExecutionService;
use App\Services\AiAgentRegistry;
use App\Services\LoginService;
use App\Services\SocialPublishingService;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();

$router->get(function () {
    $session = LoginService::getSession();
    $ownerId = (int)$session->getOwner();
    $key = trim((string)($_GET['key'] ?? ''));
    $repository = new AiAgentsRepository();
    if (!$repository->storageReady()) {
        MessageUtil::setMessage('Run db/vnv_ai_agents_required.sql first.', 'Agent storage required', 'warning');
        LocationUtils::redirectInternal('panel/agents');
    }
    $repository->seed($ownerId, AiAgentRegistry::definitions());
    $agent = $repository->find($ownerId, $key);
    if (!$agent) {
        MessageUtil::setMessage('Agent not found.');
        LocationUtils::redirectInternal('panel/agents');
    }
    $definitions = AiAgentRegistry::definitions();
    $definition = $definitions[$key] ?? [];
    $db = new Connection();
    $db->query("SELECT id,event_date,address FROM orders WHERE id_owner=:owner AND is_archived=0 ORDER BY event_date DESC LIMIT 100");
    $db->bind(':owner', $ownerId);
    $orders=$db->fetchAll();
    $db->query("SELECT id,title,status FROM cms_contents WHERE id_owner=:owner AND status IN ('GENERATED','PUBLISHED') ORDER BY updated_at DESC LIMIT 100");$db->bind(':owner',$ownerId);$contents=$db->fetchAll();
    $db->query("SELECT id,name,email,phone FROM crm_leads WHERE id_owner=:owner AND archived='NO' ORDER BY created_at DESC LIMIT 100");$db->bind(':owner',$ownerId);$leads=$db->fetchAll();

    $oneTimeWebhookToken = $_SESSION['agent_webhook_token_once'] ?? null;
    unset($_SESSION['agent_webhook_token_once']);
    $connections=[];
    if(in_array($key,['social_publisher','instagram_carousel'],true)){
        $socialAgent=$repository->find($ownerId,'social_publisher');
        foreach((new AiAgentConnectionsRepository())->all($ownerId,(int)$socialAgent->id) as $connection)$connections[$connection->platform]=$connection;
    }
    $providerConnections=[];
    if(in_array($key,['blog_writer','video_studio'],true))foreach((new AiProviderConnectionsRepository())->all($ownerId) as $providerConnection)$providerConnections[$providerConnection->provider]=$providerConnection;

    return TemplateResponse::render(__DIR__ . '/index.twig', [
        'agent' => $agent,
        'definition' => $definition,
        'runs' => $repository->recentRuns((int)$agent->id),
        'approvals' => $repository->pendingApprovals($ownerId, (int)$agent->id),
        'orders' => $orders,
        'contents' => $contents,
        'selectedContentId' => (int)($_GET['content_id']??0),
        'leads' => $leads,
        'connections' => $connections,
        'providerConnections' => $providerConnections,
        'webhookBase' => LocationUtils::pathFor('api/agents/webhook?agent=' . rawurlencode($key)),
        'oneTimeWebhookToken' => $oneTimeWebhookToken,
    ]);
});

$router->post(function () {
    $session = LoginService::getSession();
    $ownerId = (int)$session->getOwner();
    $userId = (int)$session->getId();
    $key = trim((string)($_POST['agent_key'] ?? ''));
    $action = trim((string)($_POST['action'] ?? 'run'));
    $repository = new AiAgentsRepository();
    $agent = $repository->find($ownerId, $key);
    if (!$agent) {
        MessageUtil::setMessage('Agent not found.');
        LocationUtils::redirectInternal('panel/agents');
    }

    try {
        if ($action === 'rotate_webhook') {
            $token = $repository->rotateWebhook($ownerId, $key);
            $_SESSION['agent_webhook_token_once'] = $token;
            MessageUtil::setMessage('Webhook secret rotated. Copy it now; it will only be shown once.');
        } elseif ($action === 'save_ai_provider') {
            (new AiProviderConnectionsRepository())->save($ownerId,$_POST);
            MessageUtil::setMessage('AI provider saved securely. Its API key will not be shown again.');
        } elseif ($action === 'save_connection') {
            $socialAgent=$repository->find($ownerId,'social_publisher');$platform=(string)($_POST['platform']??'');$extra=[];
            if($platform==='facebook')foreach(['app_secret','verify_token'] as $field)if(trim((string)($_POST[$field]??''))!=='')$extra[$field]=trim((string)$_POST[$field]);
            (new AiAgentConnectionsRepository())->save($ownerId,(int)$socialAgent->id,$platform,trim((string)($_POST['account_label']??'')),trim((string)($_POST['account_identifier']??'')),trim((string)($_POST['access_token']??'')),$extra);
            MessageUtil::setMessage('Social connection saved securely. The token will not be shown again.');
        } elseif ($action === 'verify_connection') {
            (new SocialPublishingService())->verify($ownerId,(string)($_POST['platform']??''));
            MessageUtil::setMessage('Social connection verified successfully.');
        } elseif ($action === 'disconnect_connection') {
            $socialAgent=$repository->find($ownerId,'social_publisher');
            (new AiAgentConnectionsRepository())->disconnect($ownerId,(int)$socialAgent->id,(string)($_POST['platform']??''));
            MessageUtil::setMessage('Social connection disconnected.');
        } elseif ($action === 'save_configuration') {
            $definition = AiAgentRegistry::definitions()[$key] ?? null;
            if (($definition['status'] ?? '') === 'SETUP_REQUIRED' && ($_POST['status'] ?? '') === 'ACTIVE') {
                throw new RuntimeException('This agent cannot be activated until its external connectors are configured and verified.');
            }
            $scheduledKeys=['estimate_follow_up','order_auditor','content_refresh','operations_risk','lead_qualification','reputation','short_video'];
            if(!empty($_POST['schedule_enabled'])&&!in_array($key,$scheduledKeys,true))throw new RuntimeException('This agent needs a selected order, lead or content item and cannot use the global daily schedule.');
            $repository->updateConfiguration($ownerId, $key, $_POST);
            MessageUtil::setMessage('Agent configuration saved.');
        } elseif ($action === 'review_approval') {
            $repository->reviewApproval(
                $ownerId,
                (int)($_POST['approval_id'] ?? 0),
                (string)($_POST['decision'] ?? ''),
                $userId,
                trim((string)($_POST['review_note'] ?? ''))
            );
            MessageUtil::setMessage('Approval decision saved. External execution remains disabled until its connector is configured.');
        } else {
            $result = (new AiAgentExecutionService($repository))->run($agent, $ownerId, $userId, 'MANUAL', [
                'order_id' => (int)($_POST['order_id'] ?? 0),
                'content_id' => (int)($_POST['content_id'] ?? 0),
                'lead_id' => (int)($_POST['lead_id'] ?? 0),
                'question' => trim((string)($_POST['question'] ?? '')),
                'networks' => array_values(array_intersect(['facebook','instagram','linkedin','youtube'],(array)($_POST['networks']??[]))),
                'provider' => (string)($_POST['provider']??''),
                'instructions' => trim((string)($_POST['instructions']??'')),
                'image_provider' => (string)($_POST['image_provider']??''),
                'regenerate_image' => !empty($_POST['regenerate_image']),
            ]);
            MessageUtil::setMessage('Agent run #' . $result['run_id'] . ' completed with status ' . $result['status'] . '.');
        }
    } catch (\Throwable $error) {
        MessageUtil::setMessage($error->getMessage(), 'Agent run failed', 'danger');
    }
    LocationUtils::redirectInternal('panel/agents/detail?key=' . rawurlencode($key));
});

$router->run();
