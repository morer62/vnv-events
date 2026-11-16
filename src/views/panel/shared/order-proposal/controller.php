<?php

use App\Repositories\InstitutionProfileRepository;
use App\Repositories\OrderProposalRepository;
use App\Repositories\OrdersRepository;
use App\Services\LoginService;
use App\Services\OrderProposalWorkflowService;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();

$loadContext = static function (bool $manager): array {
    $user = LoginService::getSession();
    if (!$user) {
        LocationUtils::redirectInternal('login');
    }
    $orderId = (int)($_GET['id'] ?? $_POST['id_order'] ?? 0);
    $data = $orderId > 0 ? (new OrdersRepository())->getByIdWithoutOwnershipCheck($orderId) : null;
    $order = $data ? (object)$data : null;
    if (!$order) {
        throw new RuntimeException('Order not found.');
    }

    if ($manager) {
        $authorized = (int)$order->id_owner === (int)$user->getOwner();
        if ((int)$user->getLevel() === 4 && !empty($_SESSION['current_institution_id'])) {
            $institution = (new InstitutionProfileRepository())->getById((int)$_SESSION['current_institution_id']);
            $authorized = $institution && (int)$institution->id_owner === (int)$order->id_owner;
        }
    } else {
        $authorized = (int)$order->id_client === (int)$user->getId();
    }
    if (!$authorized) {
        throw new RuntimeException('You do not have access to this proposal.');
    }
    return [$user, $order];
};

$validCsrf = static function (): bool {
    $stored = (string)($_SESSION['proposal_csrf_token'] ?? '');
    $posted = (string)($_POST['csrf_token'] ?? '');
    return $stored !== '' && $posted !== '' && hash_equals($stored, $posted);
};

$router->get(function () use ($proposalManagerMode, $loadContext) {
    try {
        [$user, $order] = $loadContext($proposalManagerMode);
        $repository = new OrderProposalRepository();
        $workflow = new OrderProposalWorkflowService();
        $proposal = $proposalManagerMode
            ? $workflow->getOrCreate($order, (int)$user->getId())
            : $repository->getByOrder((int)$order->id);

        if (!$proposalManagerMode && (int)($order->proposal_requires_approval ?? 0) !== 1) {
            throw new RuntimeException('This order does not require a menu / proposal approval.');
        }

        $history = $proposal ? $repository->getHistory((int)$proposal->id) : [];
        foreach ($history as $entry) {
            $entry->metadata = !empty($entry->metadata_json) ? (json_decode((string)$entry->metadata_json, true) ?: []) : [];
        }
        $_SESSION['proposal_csrf_token'] ??= bin2hex(random_bytes(32));

        return TemplateResponse::render(
            dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'components' . DIRECTORY_SEPARATOR . 'orderProposalWorkspace.twig',
            [
            'order' => $order,
            'proposal' => $proposal,
            'history' => $history,
            'manager_mode' => $proposalManagerMode,
            'csrf_token' => $_SESSION['proposal_csrf_token'],
            ]
        );
    } catch (Throwable $exception) {
        MessageUtil::setMessage($exception->getMessage(), 'Proposal', 'danger');
        LocationUtils::redirectInternal($proposalManagerMode
            ? 'panel/planner-hub/management/orders/orders'
            : 'panel/planner-hub/orders/orders');
    }
});

$router->post(function () use ($proposalManagerMode, $loadContext, $validCsrf) {
    $isJson = ($_POST['action'] ?? '') === 'upload_image';
    try {
        if (!$validCsrf()) {
            throw new RuntimeException('Your session expired. Reload the page and try again.');
        }
        [$user, $order] = $loadContext($proposalManagerMode);
        $repository = new OrderProposalRepository();
        $workflow = new OrderProposalWorkflowService();
        $proposal = $proposalManagerMode
            ? $workflow->getOrCreate($order, (int)$user->getId())
            : $repository->getByOrder((int)$order->id);
        if (!$proposal) {
            throw new RuntimeException('The proposal has not been prepared yet.');
        }
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'upload_image') {
            if (!$proposalManagerMode) throw new RuntimeException('Only the event team can upload proposal images.');
            $url = $workflow->uploadImage($_FILES['proposal_image'] ?? []);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['ok' => true, 'url' => $url], JSON_UNESCAPED_SLASHES);
            exit;
        }

        if ($action === 'save_draft') {
            if (!$proposalManagerMode) throw new RuntimeException('Only the event team can edit the proposal.');
            $workflow->saveDraft($proposal, (string)($_POST['content_html'] ?? ''), $_POST['change_note'] ?? null, (int)$user->getId());
            MessageUtil::setMessage('Proposal revision saved.');
        } elseif ($action === 'send_for_approval') {
            if (!$proposalManagerMode) throw new RuntimeException('Only the event team can send a proposal.');
            $workflow->sendForApproval($proposal, (int)$user->getId());
            MessageUtil::setMessage('Proposal sent to the client for approval.');
        } elseif ($action === 'approve') {
            if ($proposalManagerMode) throw new RuntimeException('The client must sign this proposal from their account.');
            $workflow->approve(
                $proposal,
                (int)$user->getId(),
                (string)($_POST['typed_initials'] ?? ''),
                $_FILES['signature_image'] ?? null,
                $_POST['user_local_timestamp'] ?? null
            );
            MessageUtil::setMessage('Proposal approved and signed. A PDF copy has been retained.');
        } elseif ($action === 'revoke') {
            $workflow->revoke($proposal, (int)$user->getId(), (string)($_POST['reason'] ?? ''));
            MessageUtil::setMessage('The approval was reopened and the reason was recorded.', 'Proposal reopened', 'warning');
        } else {
            throw new RuntimeException('Unknown proposal action.');
        }

        LocationUtils::redirectInternal(($proposalManagerMode
            ? 'panel/planner-hub/management/orders/orders/proposal?id='
            : 'panel/planner-hub/orders/orders/proposal?id=') . (int)$order->id);
    } catch (Throwable $exception) {
        if ($isJson) {
            http_response_code(422);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['ok' => false, 'error' => $exception->getMessage()]);
            exit;
        }
        MessageUtil::setMessage($exception->getMessage(), 'Proposal', 'danger');
        LocationUtils::redirectInternal(($proposalManagerMode
            ? 'panel/planner-hub/management/orders/orders/proposal?id='
            : 'panel/planner-hub/orders/orders/proposal?id=') . (int)($_POST['id_order'] ?? 0));
    }
});

$router->run();
