<?php

use App\Repositories\ChatThreadRepository;
use App\Repositories\ChatMessageRepository;
use App\Repositories\OrdersTeamTasksRepository;
use App\Repositories\UserRepository;
use App\Repositories\ClientsUsersRepository;
use App\Repositories\InstitutionProfileRepository;
use App\Services\LoginService;
use App\Services\TranslationService;
use App\Utils\TemplateResponse;
use App\Utils\Router;
use App\Utils\MessageUtil;

$router = new Router();

$threadRepo = new ChatThreadRepository();
$messageRepo = new ChatMessageRepository();
$userRepo = new UserRepository();
$clientsRepo = new ClientsUsersRepository();
$institutionProfileRepo = new InstitutionProfileRepository();
$ordersTaskRepo = new OrdersTeamTasksRepository();

function chat_panel_owner_id(object $user): int
{
    return (int)($user->getOwner() ?: $user->getId());
}

function chat_panel_is_participant(object $thread, int $userId): bool
{
    return (int)$thread->id_user_1 === $userId || (int)$thread->id_user_2 === $userId;
}

function chat_panel_partner_id(object $thread, int $userId): int
{
    return (int)$thread->id_user_1 === $userId ? (int)$thread->id_user_2 : (int)$thread->id_user_1;
}

function chat_panel_can_message_target(object $user, object $target, ClientsUsersRepository $clientsRepo, OrdersTeamTasksRepository $ordersTaskRepo): bool
{
    $level = (int)$user->getLevel();
    $targetId = (int)($target->id ?? 0);
    $targetLevel = (int)($target->level ?? 0);
    $targetOwnerId = (int)($target->id_owner ?? 0);
    $ownerId = chat_panel_owner_id($user);

    if ($targetId <= 0 || $targetId === (int)$user->getId()) {
        return false;
    }

    if ($level === 1) {
        return true;
    }

    if (in_array($level, \App\Entity\User::ADMIN_USER_LEVEL, true)) {
        return $targetId === $ownerId
            || $targetOwnerId === $ownerId
            || $clientsRepo->exists($targetId, $ownerId);
    }

    if ($level === 4) {
        $canChatWithClients = (int)($user->getAllowChatWithClients() ?? 0) === 1;
        $clientBelongsToOwner = $targetLevel === 5
            && ($clientsRepo->exists($targetId, $ownerId) || $targetOwnerId === $ownerId);
        $isAssignedClient = $targetLevel === 5
            && $clientBelongsToOwner
            && $ordersTaskRepo->assigneeCanChatWithOrderClient($ownerId, (int)$user->getId(), $targetId);

        return ($targetLevel !== 5 && ($targetId === $ownerId || $targetOwnerId === $ownerId))
            || ($targetLevel === 5 && $clientBelongsToOwner && ($canChatWithClients || $isAssignedClient));
    }

    return false;
}

function chat_panel_visible_users(object $user, UserRepository $userRepo, ClientsUsersRepository $clientsRepo, OrdersTeamTasksRepository $ordersTaskRepo): array
{
    $level = (int)$user->getLevel();
    $ownerId = chat_panel_owner_id($user);
    $users = [];

    if ($level === 1) {
        $users = $userRepo->getAllFlexible([
            "id !=" => $user->getId()
        ]);
    } elseif (in_array($level, \App\Entity\User::ADMIN_USER_LEVEL, true)) {
        $team = $userRepo->getAllFlexible([
            "id !=" => $user->getId(),
            "id_owner" => $ownerId,
            "level IN" => [1, 2, 3, 4]
        ]);
        $clients = $clientsRepo->getClientsByOwner($ownerId);
        $users = array_merge($team, $clients);
    } elseif ($level === 4) {
        $team = $userRepo->getAllFlexible([
            "id !=" => $user->getId(),
            "id_owner" => $ownerId,
            "level IN" => [1, 2, 3, 4]
        ]);
        $clients = (int)($user->getAllowChatWithClients() ?? 0) === 1 ? $clientsRepo->getClientsByOwner($ownerId) : [];
        $assignedClients = $ordersTaskRepo->getClientsForAssignee($ownerId, (int)$user->getId());
        $users = array_merge($team, $clients, $assignedClients);
    }

    return array_values(array_reduce($users, function (array $carry, object $candidate): array {
        $id = (int)($candidate->id ?? 0);
        if ($id > 0) {
            $carry[$id] = $candidate;
        }
        return $carry;
    }, []));
}

$router->get(function () use ($threadRepo, $messageRepo, $userRepo, $clientsRepo, $institutionProfileRepo, $ordersTaskRepo): string {
    $user = LoginService::getSession();
    $threadId = (int)($_GET['thread'] ?? 0);
    $toUserId = (int)($_GET['to'] ?? 0);

    if ($toUserId > 0 && $threadId <= 0) {
        $target = $userRepo->getOneWithoutOwnership(["id" => $toUserId]);
        if (!$target || !chat_panel_can_message_target($user, $target, $clientsRepo, $ordersTaskRepo)) {
            MessageUtil::setMessage(TranslationService::trans('messages_hub.not_allowed'));
            header("Location: index.php");
            exit;
        }

        $thread = $threadRepo->findOrCreateThread($user->getId(), $toUserId);
        header("Location: ?thread=" . $thread->id);
        exit;
    }

    $threads = $threadRepo->getAllForUser($user->getId());
    $thread = null;

    if ($threadId > 0) {
        $candidate = $threadRepo->getOne(["id" => $threadId]);
        if ($candidate && chat_panel_is_participant($candidate, (int)$user->getId())) {
            $thread = $candidate;
        }
    } elseif (!empty($threads)) {
        $thread = $threads[0];
        $threadId = (int)$thread->id;
    }

    $messages = $thread ? $messageRepo->getMessagesForThread((int)$thread->id) : [];

    if ($thread) {
        $messageRepo->markAsRead((int)$thread->id, $user->getId());
    }

    $level = (int)$user->getLevel();
    $isAdmin = in_array($level, \App\Entity\User::ADMIN_USER_LEVEL, true);
    $isVendor = $level === 4;
    $isClient = $level === 5;
    $canChatWithClients = (int)($user->getAllowChatWithClients() ?? 0);
    $users = chat_panel_visible_users($user, $userRepo, $clientsRepo, $ordersTaskRepo);
    $contactCompanies = [];
    $currentOwnerId = chat_panel_owner_id($user);
    foreach ($users as $candidate) {
        $candidateId = (int)($candidate->id ?? 0);
        $candidateLevel = (int)($candidate->level ?? 0);
        $companyOwnerId = $candidateLevel === 5 && (int)$user->getLevel() !== 1 ? $currentOwnerId : (int)($candidate->id_owner ?? 0);
        if ($companyOwnerId <= 0 && in_array($candidateLevel, \App\Entity\User::ADMIN_USER_LEVEL, true)) {
            $companyOwnerId = $candidateId;
        }
        if ($companyOwnerId <= 0) {
            continue;
        }
        $profile = $institutionProfileRepo->getByOwner($companyOwnerId);
        if ($profile && !empty($profile->company_name)) {
            $contactCompanies[$candidateId] = $profile->company_name;
        }
    }

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

$router->post(function () use ($threadRepo, $messageRepo, $userRepo, $clientsRepo, $ordersTaskRepo): void {
    $user = LoginService::getSession();
    $to = (int)($_POST["to"] ?? 0);
    $threadId = (int)($_GET["thread"] ?? 0);
    $message = trim($_POST["message"] ?? "");

    if ($to <= 0 && $threadId > 0) {
        $existingThread = $threadRepo->getOne(["id" => $threadId]);
        if ($existingThread && chat_panel_is_participant($existingThread, (int)$user->getId())) {
            $to = chat_panel_partner_id($existingThread, (int)$user->getId());
        }
    }

    if ($threadId > 0 && $message !== "") {
        $existingThread = $threadRepo->getOne(["id" => $threadId]);
        if ($existingThread && chat_panel_is_participant($existingThread, (int)$user->getId())) {
            $messageRepo->insertMessage($threadId, $user->getId(), $message);
            header("Location: ?thread=" . $threadId);
            exit;
        }
    }

    if ($to <= 0 || $message === "") {
        header("Location: index.php");
        exit;
    }

    $target = $userRepo->getOneWithoutOwnership(["id" => $to]);
    if (!$target || !chat_panel_can_message_target($user, $target, $clientsRepo, $ordersTaskRepo)) {
        MessageUtil::setMessage(TranslationService::trans('messages_hub.not_allowed'));
        header("Location: index.php");
        exit;
    }

    $thread = $threadRepo->findOrCreateThread($user->getId(), $to);
    $messageRepo->insertMessage((int)$thread->id, $user->getId(), $message);

    header("Location: ?thread=" . $thread->id);
    exit;
});

$router->run();
