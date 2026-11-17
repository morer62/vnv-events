<?php

use App\Repositories\OrderProposalRepository;
use App\Repositories\OrdersRepository;
use App\Services\OrderProposalAccessService;
use App\Services\OrderProposalWorkflowService;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();

$loadContext = static function (): array {
    $token = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));
    if ($token === '') {
        throw new RuntimeException('The proposal link is missing.');
    }

    $payload = (new OrderProposalAccessService())->validateToken($token);
    $orderData = (new OrdersRepository())->getByIdWithoutOwnershipCheck((int)$payload['order_id']);
    $order = $orderData ? (object)$orderData : null;
    if (!$order || (int)$order->id_client !== (int)$payload['user_id']) {
        throw new RuntimeException('The proposal link is not available.');
    }

    return [$token, $payload, $order];
};

$invalidLink = static function (): never {
    http_response_code(404);
    LocationUtils::redirectInternal('404');
};

$router->get(function () use ($loadContext, $invalidLink) {
    try {
        [$token, $payload, $order] = $loadContext();
        $repository = new OrderProposalRepository();
        $proposal = $repository->getByOrder((int)$order->id);
        $history = $proposal ? $repository->getHistory((int)$proposal->id) : [];
        foreach ($history as $entry) {
            $entry->metadata = !empty($entry->metadata_json)
                ? (json_decode((string)$entry->metadata_json, true) ?: [])
                : [];
        }

        $_SESSION['proposal_public_csrf_token'] ??= bin2hex(random_bytes(32));

        return TemplateResponse::render(
            __DIR__ . DIRECTORY_SEPARATOR . 'index.twig',
            [
                'order' => $order,
                'proposal' => $proposal,
                'history' => $history,
                'token' => $token,
                'csrf_token' => $_SESSION['proposal_public_csrf_token'],
                'skip_public_gtm_initial' => true,
                'skip_public_app_bundle' => true,
                'skip_public_google_maps' => true,
                'skip_public_stripe' => true,
            ]
        );
    } catch (Throwable) {
        $invalidLink();
    }
});

$router->post(function () use ($loadContext, $invalidLink) {
    try {
        $storedCsrf = (string)($_SESSION['proposal_public_csrf_token'] ?? '');
        $postedCsrf = (string)($_POST['csrf_token'] ?? '');
        if ($storedCsrf === '' || $postedCsrf === '' || !hash_equals($storedCsrf, $postedCsrf)) {
            throw new RuntimeException('Your session expired. Reload the page and try again.');
        }

        [$token, $payload, $order] = $loadContext();
        $proposal = (new OrderProposalRepository())->getByOrder((int)$order->id);
        if (!$proposal) {
            throw new RuntimeException('The proposal has not been prepared yet.');
        }

        $workflow = new OrderProposalWorkflowService();
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'approve') {
            $workflow->approve(
                $proposal,
                (int)$payload['user_id'],
                (string)($_POST['typed_initials'] ?? ''),
                $_FILES['signature_image'] ?? null,
                $_POST['user_local_timestamp'] ?? null
            );
            MessageUtil::setMessage('Proposal approved and signed. A PDF copy has been retained.');
        } elseif ($action === 'revoke') {
            $workflow->revoke($proposal, (int)$payload['user_id'], (string)($_POST['reason'] ?? ''));
            MessageUtil::setMessage('The approval was reopened and the reason was recorded.', 'Proposal reopened', 'warning');
        } else {
            throw new RuntimeException('Unknown proposal action.');
        }

        LocationUtils::redirectInternal('proposal-access?token=' . rawurlencode($token));
    } catch (InvalidArgumentException|RuntimeException $exception) {
        MessageUtil::setMessage($exception->getMessage(), 'Proposal', 'danger');
        $token = trim((string)($_POST['token'] ?? ''));
        if ($token !== '') {
            LocationUtils::redirectInternal('proposal-access?token=' . rawurlencode($token));
        }
        $invalidLink();
    } catch (Throwable) {
        $invalidLink();
    }
});

$router->run();
