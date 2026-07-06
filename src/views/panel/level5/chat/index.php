<?php

use App\Repositories\ChatThreadRepository;
use App\Repositories\ChatMessageRepository;
use App\Repositories\OrdersTeamTasksRepository;
use App\Repositories\StoreOrderTasksRepository;
use App\Repositories\UserRepository;
use App\Repositories\ClientsUsersRepository;
use App\Repositories\InstitutionProfileRepository;
use App\Services\LoginService;
use App\Services\TranslationService;
use App\Services\UserWorkspaceContextService;
use App\Utils\TemplateResponse;
use App\Utils\Router;
use App\Utils\MessageUtil;

$router = new Router();

$threadRepo = new ChatThreadRepository();
$messageRepo = new ChatMessageRepository();
$userRepo = new UserRepository();
$clientsRepo = new ClientsUsersRepository();
$workspaceContextService = new UserWorkspaceContextService();
$institutionProfileRepo = new InstitutionProfileRepository();
$ordersTaskRepo = new OrdersTeamTasksRepository();
$storeTaskRepo = new StoreOrderTasksRepository();

$router->get(function () use ($threadRepo, $messageRepo, $userRepo, $clientsRepo, $workspaceContextService, $institutionProfileRepo, $ordersTaskRepo, $storeTaskRepo): string {
    $user = LoginService::getSession();
    $currentUserId = (int)$user->getId();
    $threadId = $_GET['thread'] ?? null;
    $clientContext = $workspaceContextService->getClientContext($user);
    $selectedOwnerId = (int)($clientContext["selectedOwnerId"] ?? 0);
    $associatedOwnerIds = $clientsRepo->getOwnerIdsForClient($currentUserId);
    $directOwnerId = (int)($user->getOwner() ?? 0);
    if ($directOwnerId > 0 && !in_array($directOwnerId, $associatedOwnerIds, true)) {
        $associatedOwnerIds[] = $directOwnerId;
    }
    $contactCompanies = [];
    foreach ($clientsRepo->getAssociatedCompaniesForClient($currentUserId) as $company) {
        $contactCompanies[(int)$company->owner_id] = $company->company_name ?? "";
    }

    $toUserId = $_GET['to'] ?? null;
    if ($toUserId && !$threadId) {
        if (!isClientChatTargetAllowed($currentUserId, $toUserId, $associatedOwnerIds, $selectedOwnerId, $userRepo, $clientsRepo, $ordersTaskRepo, $storeTaskRepo, false)) {
            MessageUtil::setMessage(TranslationService::trans('messages_hub.client_company_only'));
            header("Location: index.php");
            exit;
        }
        $thread = $threadRepo->findOrCreateThread($currentUserId, $toUserId);
        $threadId = $thread->id;
        header("Location: ?thread=" . $threadId);
        exit;
    }

    $threads = array_values(array_filter($threadRepo->getAllForUser($currentUserId), function ($thread) use ($currentUserId) {
        $partnerId = $thread->id_user_1 == $currentUserId ? (int)$thread->id_user_2 : (int)$thread->id_user_1;
        return true;
    }));
    $thread = null;

    if ($threadId) {
        $thread = $threadRepo->getOne(["id" => (int)$threadId]);
        if (
            !$thread
            || ((int)$thread->id_user_1 !== $currentUserId && (int)$thread->id_user_2 !== $currentUserId)
        ) {
            $thread = null;
            $threadId = null;
        }
    } elseif (!empty($threads)) {
        foreach ($threads as $t) {
            $partnerId = $t->id_user_1 == $currentUserId ? $t->id_user_2 : $t->id_user_1;
            if ($partnerId != 2) {
                $thread = $t;
                $threadId = $t->id;
                break;
            }
        }

        if (!$thread) {
            $thread = $threads[0];
            $threadId = $thread->id;
        }
    }

    $messages = $thread ? $messageRepo->getMessagesForThread((int)$thread->id) : [];

    if ($threadId) {
        $messageRepo->markAsRead((int)$threadId, $currentUserId);
    }

    $level = $user->getLevel();
    $isAdmin = in_array($level, \App\Entity\User::ADMIN_USER_LEVEL);
    $isVendor = $level === 4;
    $isClient = $level === 5;
    $canChatWithClients = $user->getAllowChatWithClients() ?? 0;

    $users = [];

    if ($isAdmin) {
        $team = $userRepo->getAllBy(["id_owner" => $user->getId()]);
        $clients = $clientsRepo->getClientsByOwner($user->getId());
        $users = array_merge($team, $clients);
    } elseif ($isVendor) {
        $ownerId = $user->getOwner();
        $team = $userRepo->getAllFlexible([
            "id !=" => $user->getId(),
            "id_owner" => $ownerId,
            "level IN" => [1, 2, 3, 4]
        ]);
        $clients = $canChatWithClients ? $clientsRepo->getClientsByOwner($ownerId) : [];
        $users = array_merge($team, $clients);
    } elseif ($isClient) {
        foreach ($associatedOwnerIds as $ownerId) {
            if ($selectedOwnerId <= 0 || (int)$ownerId === $selectedOwnerId) {
                $owner = $userRepo->getOneWithoutOwnership(["id" => (int)$ownerId]);
                if ($owner) {
                    $owner->company_name = $contactCompanies[(int)$ownerId] ?? null;
                    $users[] = $owner;
                }
            }
        }

        $assignedOwnerIds = $selectedOwnerId > 0 ? [$selectedOwnerId] : $associatedOwnerIds;
        foreach ($ordersTaskRepo->getAssigneesForClientOrders($currentUserId, $assignedOwnerIds) as $assignedTeamMember) {
            $assignedTeamMember->company_name = $contactCompanies[(int)($assignedTeamMember->id_owner ?? 0)] ?? null;
            $users[] = $assignedTeamMember;
        }

        foreach ($threads as $t) {
            $partnerId = $t->id_user_1 == $currentUserId ? $t->id_user_2 : $t->id_user_1;
            $partner = $userRepo->getOneWithoutOwnership(["id" => $partnerId]);

            if ($partner) {
                $partnerCompanyOwnerId = (int)($partner->level ?? 0) === 4 ? (int)($partner->id_owner ?? 0) : (int)$partner->id;
                $partner->company_name = $contactCompanies[$partnerCompanyOwnerId] ?? null;
                $users[] = $partner;
            }
        }
    }

    $users = array_values(array_reduce($users, function (array $carry, object $candidate): array {
        $id = (int)($candidate->id ?? 0);
        if ($id > 0) {
            $carry[$id] = $candidate;
        }
        return $carry;
    }, []));

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "threads" => $threads,
        "thread" => $thread,
        "messages" => $messages,
        "users" => $users,
        "contact_companies" => $contactCompanies,
        "me" => $user,
        "is_admin" => $isAdmin,
        "is_vendor" => $isVendor,
        "is_client" => $isClient,
        "can_chat_with_clients" => $canChatWithClients
    ]);
});

$router->post(function () use ($threadRepo, $messageRepo, $userRepo, $clientsRepo, $ordersTaskRepo, $storeTaskRepo): void {
    $user = LoginService::getSession();
    $currentUserId = (int)$user->getId();
    $to = $_POST["to"] ?? null;
    $threadId = (int)($_GET["thread"] ?? 0);
    $message = trim($_POST["message"] ?? "");

    if ((!$to || (int)$to <= 0) && $threadId > 0) {
        $existingThread = $threadRepo->getOne(["id" => $threadId]);
        if (
            $existingThread
            && ((int)$existingThread->id_user_1 === $currentUserId || (int)$existingThread->id_user_2 === $currentUserId)
        ) {
            $to = (int)$existingThread->id_user_1 === $currentUserId ? (int)$existingThread->id_user_2 : (int)$existingThread->id_user_1;
        }
    }

    if ($threadId > 0 && $message !== "") {
        $existingThread = $threadRepo->getOne(["id" => $threadId]);
        if (
            $existingThread
            && ((int)$existingThread->id_user_1 === $currentUserId || (int)$existingThread->id_user_2 === $currentUserId)
        ) {
            $messageRepo->insertMessage($threadId, $currentUserId, $message);
            header("Location: ?thread=" . $threadId);
            exit;
        }
    }

    if (!$to || $message === "") {
        header("Location: index.php");
        exit;
    }

    $target = $userRepo->getOneWithoutOwnership(["id" => (int)$to]);

    if (!$target) {
        MessageUtil::setMessage(TranslationService::trans('messages_hub.user_not_found'));
        header("Location: index.php");
        exit;
    }

    $level = $user->getLevel();
    $targetLevel = $target->level;
    $canChatWithClients = $user->getAllowChatWithClients() ?? 0;
    $clientContext = (new UserWorkspaceContextService())->getClientContext($user);
    $selectedOwnerId = (int)($clientContext["selectedOwnerId"] ?? 0);
    $associatedOwnerIds = $clientsRepo->getOwnerIdsForClient($currentUserId);
    $directOwnerId = (int)($user->getOwner() ?? 0);
    if ($directOwnerId > 0 && !in_array($directOwnerId, $associatedOwnerIds, true)) {
        $associatedOwnerIds[] = $directOwnerId;
    }
    $contactCompanies = [];
    foreach ($clientsRepo->getAssociatedCompaniesForClient($currentUserId) as $company) {
        $contactCompanies[(int)$company->owner_id] = $company->company_name ?? "";
    }

    $isAllowed =
        in_array($level, \App\Entity\User::ADMIN_USER_LEVEL) ||
        ($level === 4 && $targetLevel !== 5) ||
        ($level === 4 && $targetLevel === 5 && $canChatWithClients) ||
        ($level === 5 && (clientHasExistingThread($threadRepo, $currentUserId, (int)$to) || isClientChatTargetAllowed($currentUserId, (int)$to, $associatedOwnerIds, $selectedOwnerId, $userRepo, $clientsRepo, $ordersTaskRepo, $storeTaskRepo, true)));

    if (!$isAllowed) {
        MessageUtil::setMessage(TranslationService::trans('messages_hub.not_allowed'));
        header("Location: index.php");
        exit;
    }

    $thread = $threadRepo->findOrCreateThread($currentUserId, (int)$to);
    $messageRepo->insertMessage((int)$thread->id, $currentUserId, $message);

    header("Location: ?thread=" . $thread->id);
    exit;
});

$router->run();

function isClientChatTargetAllowed(
    int $clientId,
    int $targetId,
    array $associatedOwnerIds,
    int $selectedOwnerId,
    UserRepository $userRepo,
    ClientsUsersRepository $clientsRepo,
    OrdersTeamTasksRepository $ordersTaskRepo,
    StoreOrderTasksRepository $storeTaskRepo,
    bool $allowExistingTeamThread
): bool {
    if ($targetId <= 0 || $targetId === $clientId) {
        return false;
    }

    if (in_array($targetId, $associatedOwnerIds, true)) {
        return $selectedOwnerId <= 0 || $targetId === $selectedOwnerId;
    }

    $target = $userRepo->getOneWithoutOwnership(["id" => $targetId]);
    if (!$target || (int)($target->level ?? 0) !== 4) {
        return false;
    }

    $ownerId = (int)($target->id_owner ?? 0);
    if (!in_array($ownerId, $associatedOwnerIds, true)) {
        return false;
    }

    if ($selectedOwnerId > 0 && $ownerId !== $selectedOwnerId) {
        return false;
    }

    $client = $userRepo->getOneWithoutOwnership(["id" => $clientId]);
    $clientBelongsToOwner = $client && (int)($client->id_owner ?? 0) === $ownerId;

    if (!$clientBelongsToOwner && !$clientsRepo->exists($clientId, $ownerId)) {
        return false;
    }

    $hasAssignedWork = $storeTaskRepo->assigneeCanChatWithClient($ownerId, $targetId, $clientId)
        || $ordersTaskRepo->assigneeCanChatWithOrderClient($ownerId, $targetId, $clientId);

    return $hasAssignedWork || (int)($target->allow_chat_with_clients ?? 0) === 1;
}

function clientHasExistingThread(ChatThreadRepository $threadRepo, int $clientId, int $targetId): bool
{
    $ids = [$clientId, $targetId];
    sort($ids);

    return (bool)$threadRepo->getOne([
        "id_user_1" => $ids[0],
        "id_user_2" => $ids[1]
    ]);
}
