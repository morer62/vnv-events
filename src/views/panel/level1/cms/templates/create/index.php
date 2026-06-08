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

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        'site_key' => SiteContext::siteKey(),
        'site_name' => SiteContext::siteName(),
        'default_preview_html' => $repo->defaultPreviewHtml(),
    ]);
});

$router->post(function () {
    $repo = new CmsTemplatesRepository();
    $repo->db = new Connection();
    $user = LoginService::getSession();

    $name = trim($_POST['name'] ?? '');
    $key = trim($_POST['template_key'] ?? '');
    $type = normalizeCmsTemplateType((string)($_POST['type'] ?? 'page'));
    $description = trim($_POST['description'] ?? '');
    $structure = $_POST['template_structure_json'] ?? '';
    $preview = $_POST['preview_html'] ?? '';
    $cssText = $_POST['css_text'] ?? '';
    $metadataJson = $_POST['metadata_json'] ?? '';
    $status = trim($_POST['status'] ?? 'ACTIVE') ?: 'ACTIVE';
    $siteKey = SiteContext::siteKey();
    $ownerId = $user ? $user->getOwner() : SiteContext::businessOwnerId();

    if (!$name || !$key) {
        MessageUtil::setMessage("Name and key required.");
        LocationUtils::redirectInternal("panel/cms/templates/create");
    }

    if ($repo->templateKeyExists($key, 0, $siteKey, $ownerId)) {
        MessageUtil::setMessage("Template key exists.");
        LocationUtils::redirectInternal("panel/cms/templates/create");
    }

    $repo->add([
        'id_owner' => $ownerId,
        'site_key' => $siteKey,
        'name' => $name,
        'template_key' => $key,
        'description' => $description,
        'type' => $type,
        'preview_html' => $preview,
        'template_structure_json' => $structure,
        'css_text' => $cssText,
        'metadata_json' => $metadataJson,
        'status' => $status
    ]);

    LocationUtils::redirectInternal("panel/cms/templates");
});

$router->run();

function normalizeCmsTemplateType(string $type): string
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
