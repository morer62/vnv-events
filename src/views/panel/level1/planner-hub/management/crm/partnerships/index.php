<?php

use App\Repositories\CommercialPartnershipRepository;
use App\Services\LoginService;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\Router;
use App\Utils\SiteContext;
use App\Utils\TemplateResponse;

$router = new Router();

$router->get(function () {
    $user = LoginService::getSession();
    $ownerId = (int)$user->getOwner();
    $siteKey = SiteContext::siteKey();
    $canManageFinance = in_array((int)$user->getLevel(), [1, 6], true);
    if (empty($_SESSION['commercial_crm_csrf'])) {
        $_SESSION['commercial_crm_csrf'] = bin2hex(random_bytes(24));
    }

    $filters = [
        'pipeline' => preg_replace('/[^A-Z_]/', '', strtoupper(trim((string)($_GET['pipeline'] ?? '')))),
        'stage' => preg_replace('/[^A-Z_]/', '', strtoupper(trim((string)($_GET['stage'] ?? '')))),
        'q' => mb_substr(trim((string)($_GET['q'] ?? '')), 0, 120),
    ];
    if (!$canManageFinance) {
        $filters['assigned_user_id'] = (int)$user->getId();
    }

    $error = null;
    $finance = ['grants' => [], 'commissions' => [], 'invoice_links' => []];
    $teamMembers = [];
    try {
        $repository = new CommercialPartnershipRepository();
        $dashboard = $repository->dashboard($ownerId, $siteKey, $filters);
        if ($canManageFinance) {
            $finance = $repository->financeSnapshot($ownerId, $siteKey);
            $teamMembers = $repository->teamMembers($ownerId);
        }
    } catch (Throwable $e) {
        error_log('Commercial CRM dashboard error: ' . $e->getMessage());
        $dashboard = ['items' => [], 'counts' => [], 'stages' => CommercialPartnershipRepository::allowedStages()];
        $error = 'Partnership Pipelines is waiting for its database migration.';
    }

    return TemplateResponse::render(__DIR__ . '/index.twig', [
        'dashboard' => $dashboard,
        'filters' => $filters,
        'csrf' => $_SESSION['commercial_crm_csrf'],
        'load_error' => $error,
        'can_manage_finance' => $canManageFinance,
        'finance' => $finance,
        'team_members' => $teamMembers,
    ]);
});

$router->post(function () {
    $user = LoginService::getSession();
    $token = (string)($_POST['_token'] ?? '');
    if ($token === '' || !hash_equals((string)($_SESSION['commercial_crm_csrf'] ?? ''), $token)) {
        MessageUtil::setMessage('The update session expired. Please try again.', 'CRM', 'warning');
        LocationUtils::redirectInternal('panel/planner-hub/management/crm/partnerships');
    }
    $id = (int)($_POST['opportunity_id'] ?? 0);
    $action = preg_replace('/[^a-z_]/', '', strtolower(trim((string)($_POST['action'] ?? 'stage'))));
    $repository = new CommercialPartnershipRepository();
    try {
        $ownerId = (int)$user->getOwner();
        $siteKey = SiteContext::siteKey();
        $actorId = (int)$user->getId();
        $canManageFinance = in_array((int)$user->getLevel(), [1, 6], true);
        if ($action === 'stage') {
            $stage = preg_replace('/[^A-Z_]/', '', strtoupper(trim((string)($_POST['stage'] ?? ''))));
            $updated = $repository->updateStage($ownerId, $siteKey, $id, $stage, $actorId);
            MessageUtil::setMessage($updated ? 'Pipeline stage updated.' : 'That stage is not valid for this pipeline.', 'CRM', $updated ? 'success' : 'warning');
        } elseif (!$canManageFinance) {
            MessageUtil::setMessage('Only Direction can approve financial terms.', 'CRM permissions', 'warning');
        } elseif ($action === 'support_grant') {
            $repository->approveSupportGrant($ownerId, $siteKey, $id, [
                'consultation_hours' => (float)($_POST['consultation_hours'] ?? 0),
                'discount_food_staff_percent' => (float)($_POST['discount_food_staff_percent'] ?? 0),
                'discount_decor_percent' => (float)($_POST['discount_decor_percent'] ?? 0),
                'approved_scope' => trim((string)($_POST['approved_scope'] ?? '')),
            ], $actorId);
            MessageUtil::setMessage('Nonprofit support plan approved and audited.', 'CRM', 'success');
        } elseif ($action === 'commission') {
            $result = $repository->recordCommission(
                $ownerId,
                $siteKey,
                $id,
                (string)($_POST['source_type'] ?? ''),
                (string)($_POST['source_reference'] ?? ''),
                (float)($_POST['collected_amount'] ?? 0),
                $actorId
            );
            MessageUtil::setMessage('Commission basis recorded for approval: $' . number_format((float)$result['eligible_amount'], 2) . '.', 'CRM', 'success');
        } elseif ($action === 'link_invoice') {
            $repository->linkInvoice($ownerId, $siteKey, $id, (int)($_POST['invoice_id'] ?? 0), $actorId);
            MessageUtil::setMessage('Existing VNV invoice linked to the opportunity.', 'CRM', 'success');
        } elseif ($action === 'approve_commission') {
            $repository->approveCommission($ownerId, $siteKey, (int)($_POST['commission_id'] ?? 0), $actorId);
            MessageUtil::setMessage('Commission split approved and audited.', 'CRM', 'success');
        } elseif ($action === 'assign_owner') {
            $repository->assignOpportunity($ownerId, $siteKey, $id, (int)($_POST['assigned_user_id'] ?? 0), $actorId);
            MessageUtil::setMessage('Opportunity owner assigned and audited.', 'CRM', 'success');
        } else {
            MessageUtil::setMessage('Unknown CRM action.', 'CRM', 'warning');
        }
    } catch (Throwable $e) {
        error_log('Commercial CRM stage update error: ' . $e->getMessage());
        MessageUtil::setMessage($e instanceof InvalidArgumentException ? $e->getMessage() : 'The CRM update could not be completed.', 'CRM', 'error');
    }
    LocationUtils::redirectInternal('panel/planner-hub/management/crm/partnerships');
});

$router->run();
