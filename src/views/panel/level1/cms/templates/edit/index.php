<?php

use App\Repositories\CmsTemplatesRepository;
use App\Repositories\Connection;
use App\Services\LoginService;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\Router;
use App\Utils\SiteContext;
use App\Utils\TemplateResponse;

$router = new Router();

$router->get(function () {
    $repo = new CmsTemplatesRepository();
    $repo->db = new Connection();
    $id = (int)($_GET['id'] ?? 0);

    $template = $repo->getOneForPanel($id, SiteContext::siteKey());

    if (!$template) {
        MessageUtil::setMessage("Template not found for this site.");
        LocationUtils::redirectInternal("panel/cms/templates");
    }

    if (isset($template->site_key) && $template->site_key !== SiteContext::siteKey()) {
        MessageUtil::setMessage("Shared templates cannot be edited from this site.");
        LocationUtils::redirectInternal("panel/cms/templates");
    }

    $template->type = normalizeCmsTemplateTypeEdit((string)($template->type ?? 'page'));

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "site_key" => SiteContext::siteKey(),
        "site_name" => SiteContext::siteName(),
        "template" => $template
    ]);
});

$router->post(function () {
    $repo = new CmsTemplatesRepository();
    $repo->db = new Connection();
    $user = LoginService::getSession();
    $id = (int)($_POST['id'] ?? 0);
    $siteKey = SiteContext::siteKey();
    $ownerId = $user ? $user->getOwner() : SiteContext::businessOwnerId();
    $key = trim((string)($_POST['template_key'] ?? ''));

    if ($key === '') {
        MessageUtil::setMessage("Template key required.");
        LocationUtils::redirectInternal("panel/cms/templates/edit?id=" . $id);
    }

    if ($repo->templateKeyExists($key, $id, $siteKey, $ownerId)) {
        MessageUtil::setMessage("Template key exists for this site.");
        LocationUtils::redirectInternal("panel/cms/templates/edit?id=" . $id);
    }

    $repo->update([
        'id_owner' => $ownerId,
        'site_key' => $siteKey,
        'name' => trim((string)($_POST['name'] ?? '')),
        'template_key' => $key,
        'description' => trim((string)($_POST['description'] ?? '')),
        'type' => normalizeCmsTemplateTypeEdit((string)($_POST['type'] ?? 'page')),
        'preview_html' => $_POST['preview_html'] ?? '',
        'template_structure_json' => $_POST['template_structure_json'] ?? '',
        'css_text' => $_POST['css_text'] ?? '',
        'metadata_json' => $_POST['metadata_json'] ?? '',
        'status' => trim((string)($_POST['status'] ?? 'ACTIVE')) ?: 'ACTIVE',
    ], ['id' => $id]);

    LocationUtils::redirectInternal("panel/cms/templates");
});

$router->run();

function normalizeCmsTemplateTypeEdit(string $type): string
{
    $type = strtolower(trim($type));

    if ($type === 'location') {
        return 'location';
    }

    if (in_array($type, ['blog', 'post', 'guide', 'faq_page', 'comparison', 'case_study'], true)) {
        return 'blog';
    }

    return 'page';
}
