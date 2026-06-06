<?php

use App\Services\LoginService;
use App\Repositories\MusicSessionRepository;
use App\Repositories\MusicSessionsCategoryRepository;
use App\Repositories\MusicSessionsKeywordRepository;
use App\Repositories\MusicSessionsKeywordsRelationsRepository;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\Router;
use App\Utils\TemplateResponse;
use App\Utils\UserContext;

$router = new Router();

$router->get(function () {
    $context = UserContext::get();
    $user = LoginService::getSession();
    $categoryRepo = new MusicSessionsCategoryRepository();
    $keywordRepo = new MusicSessionsKeywordRepository();

    $userId = $user->getId();
    $categories = $categoryRepo->getAllByUser($userId);
    $keywords = $keywordRepo->getAllByUser($userId);

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        ...$context,
        "categories" => $categories,
        "keywords" => $keywords
    ]);
});

$router->post(function () {
    $user = LoginService::getSession();
    $sessionRepo = new MusicSessionRepository();
    $keywordRepo = new MusicSessionsKeywordRepository();
    $keywordRelationsRepo = new MusicSessionsKeywordsRelationsRepository();

    $title = trim($_POST["title"] ?? '');
    $description = trim($_POST["description"] ?? '');
    $url = trim($_POST["url"] ?? '');
    $platform = trim($_POST["platform"] ?? '');
    $idCategory = !empty($_POST["id_category"]) ? (int)$_POST["id_category"] : null;
    $isActive = isset($_POST["is_active"]) ? 1 : 0;

    if (empty($title) || empty($url) || empty($platform)) {
        MessageUtil::setMessage("Title, URL, and Platform are required.");
        LocationUtils::reload();
    }

    $embedCode = $sessionRepo->generateEmbedCode($url, $platform);

    $data = [
        "title" => $title,
        "description" => $description ?: null,
        "url" => $url,
        "platform" => $platform,
        "embed_code" => $embedCode ?: null,
        "id_category" => $idCategory,
        "is_active" => $isActive,
        ...LoginService::getUserIdAsArray(true)
    ];

    if ($sessionRepo->add($data)) {
        $sessionId = $sessionRepo->db->lastId();
        
        $keywordIds = [];
        $userId = $user->getId();
        
        if (!empty($_POST["keywords"])) {
            if (is_array($_POST["keywords"])) {
                foreach ($_POST["keywords"] as $keywordId) {
                    $keywordIds[] = (int)$keywordId;
                }
            } else {
                $keywordIds[] = (int)$_POST["keywords"];
            }
        }
        
        if (!empty($_POST["new_keywords"])) {
            $newKeywords = explode(',', $_POST["new_keywords"]);
            foreach ($newKeywords as $keyword) {
                $keyword = trim($keyword);
                if (!empty($keyword)) {
                    $keywordId = $keywordRepo->getOrCreate($keyword, $userId);
                    if ($keywordId > 0) {
                        $keywordIds[] = $keywordId;
                    }
                }
            }
        }
        
        if (!empty($keywordIds)) {
            $keywordRelationsRepo->setSessionKeywords($sessionId, $keywordIds);
        }
        
        MessageUtil::setMessage("multimedia session created successfully.");
        LocationUtils::redirectInternal("panel/multimedia-sessions");
    } else {
        MessageUtil::setMessage("Error creating multimedia session. Please try again.");
        LocationUtils::reload();
    }
});

$router->run();

