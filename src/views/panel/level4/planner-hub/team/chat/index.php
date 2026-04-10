<?php

use App\Repositories\ChatThreadRepository;
use App\Repositories\ChatMessageRepository;
use App\Repositories\UserRepository;
use App\Repositories\ClientsUsersRepository;
use App\Services\LoginService;
use App\Utils\TemplateResponse;
use App\Utils\Router;
use App\Utils\MessageUtil;

$router = new Router();

$threadRepo = new ChatThreadRepository();
$messageRepo = new ChatMessageRepository();
$userRepo = new UserRepository();
$clientsRepo = new ClientsUsersRepository();

$router->get(function () use ($threadRepo, $messageRepo, $userRepo, $clientsRepo): string {
    $user = LoginService::getSession();
    $threadId = $_GET['thread'] ?? null;

    

    $threads = $threadRepo->getAllForUser($user->getId());
    $thread = null;

   
    

    if ($threadId) {
        $thread = $threadRepo->getOne(["id" => (int)$threadId]);
    } elseif (!empty($threads)) {
        // buscar un thread donde el partner NO sea el admin (id 2)
        foreach ($threads as $t) {
            $partnerId = $t->id_user_1 == $user->getId() ? $t->id_user_2 : $t->id_user_1;
            if ($partnerId != 2) {
                $thread = $t;
                $threadId = $t->id;
                break;
            }
        }

        // si no se encontró otro, usar el primero
        if (!$thread) {
            $thread = $threads[0];
            $threadId = $thread->id;
        }
    }

    $messages = $thread ? $messageRepo->getMessagesForThread((int)$thread->id) : [];

    if ($threadId) {
        $messageRepo->markAsRead((int)$threadId, $user->getId());
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
        foreach ($threads as $t) {
            $partnerId = $t->id_user_1 == $user->getId() ? $t->id_user_2 : $t->id_user_1;
            $partner = $userRepo->getOne(["id" => $partnerId]);
            if ($partner) $users[] = $partner;
        }
    }


     

    $users = array_filter(
        array_reduce($users, function ($carry, $u) {
            $email = is_object($u) && property_exists($u, 'email') ? $u->email : null;
            if ($email) {
                $carry[$email] = $u;
            }
            return $carry;
        }, []),
        fn($u) => true
    );


    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "threads" => $threads,
        "thread" => $thread,
        "messages" => $messages,
        "users" => $users,
        "me" => $user,
        "is_admin" => $isAdmin,
        "is_vendor" => $isVendor,
        "can_chat_with_clients" => $canChatWithClients
    ]);
});

$router->post(function () use ($threadRepo, $messageRepo, $userRepo, $clientsRepo): void {
    $user = LoginService::getSession();
    $to = $_POST["to"] ?? null;
    $message = trim($_POST["message"] ?? "");

    if (!$to || $message === "") {
        header("Location: index.php");
        exit;
    }

    $target = $userRepo->getByIdEvenIfAssociated((int)$to);
    if (!$target) {
        MessageUtil::setMessage("User not found.");
        header("Location: index.php");
        exit;
    }

    $level = $user->getLevel();
    $targetLevel = $target->level;
    $canChatWithClients = $user->getAllowChatWithClients() ?? 0;

    $isAllowed =
        in_array($level, \App\Entity\User::ADMIN_USER_LEVEL) ||
        ($level === 4 && $targetLevel !== 5) ||
        ($level === 4 && $targetLevel === 5 && $canChatWithClients) ||
        ($level === 5 && $threadRepo->findOrCreateThread($user->getId(), (int)$to));

    if (!$isAllowed) {
        MessageUtil::setMessage("You are not allowed to start this conversation.");
        header("Location: index.php");
        exit;
    }

    $thread = $threadRepo->findOrCreateThread($user->getId(), (int)$to);
    $messageRepo->insertMessage((int)$thread->id, $user->getId(), $message);

    header("Location: ?thread=" . $thread->id);
    exit;
});

$router->run();
