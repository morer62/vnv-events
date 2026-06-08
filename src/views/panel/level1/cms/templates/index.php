<?php

use App\Repositories\CmsTemplatesRepository;
use App\Repositories\Connection;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\Router;
use App\Utils\SiteContext;
use App\Utils\TemplateResponse;

$router = new Router();

$router->get(function () {
    $repo = new CmsTemplatesRepository();
    $repo->db = new Connection();
    $siteKey = SiteContext::siteKey();

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "title" => "Templates",
        "site_key" => $siteKey,
        "site_name" => SiteContext::siteName(),
        "templates" => $repo->getAllForPanel($siteKey)
    ]);
});

$router->post(function () {
    $repo = new CmsTemplatesRepository();
    $repo->db = new Connection();

    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);

    if (!$id) {
        MessageUtil::setMessage("Invalid template ID.");
        LocationUtils::redirectInternal("panel/cms/templates");
    }

    $template = $repo->getOneForPanel($id, SiteContext::siteKey());
    if (!$template) {
        MessageUtil::setMessage("Template not found for this site.");
        LocationUtils::redirectInternal("panel/cms/templates");
    }

    if (isset($template->site_key) && $template->site_key !== SiteContext::siteKey()) {
        MessageUtil::setMessage("Shared templates cannot be changed from this site.");
        LocationUtils::redirectInternal("panel/cms/templates");
    }

    if ($action === 'delete') {
        $repo->delete(['id' => $id]);
        MessageUtil::setMessage("Template deleted.");
    }

    if ($action === 'activate') {
        $repo->update(['status' => 'ACTIVE'], ['id' => $id]);
        MessageUtil::setMessage("Template activated.");
    }

    if ($action === 'deactivate') {
        $repo->update(['status' => 'INACTIVE'], ['id' => $id]);
        MessageUtil::setMessage("Template deactivated.");
    }

    LocationUtils::redirectInternal("panel/cms/templates");
});

try {
    $router->run();
} catch (Exception $e) {
    echo $e->getMessage();
}
