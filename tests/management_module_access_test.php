<?php

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Entity\User;
use App\Entity\UserPermissions;
use App\Entity\UserPermissions2;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

function assertModuleAccess(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function makeLevel4User(array $permissions): User
{
    return new User(
        4001,
        'Module',
        'Tester',
        'module.tester@example.test',
        'not-used',
        '+13055550199',
        1,
        null,
        4,
        null,
        1,
        $permissions,
        0,
        'FREE',
        '1'
    );
}

$catalogSlugs = UserPermissions::managementModuleSlugs();
foreach (['mochi', 'crm_clients', 'crm_sponsors', 'storage', 'orders', 'payroll', 'growth_hub'] as $required) {
    assertModuleAccess(in_array($required, $catalogSlugs, true), "Missing management module {$required}");
}

$explicit = makeLevel4User([
    new UserPermissions2(UserPermissions::MANAGEMENT_CONFIGURED, 'feature_full'),
    new UserPermissions2(UserPermissions::STORAGE, 'feature_full'),
    new UserPermissions2(UserPermissions::CRM_SPONSORS, 'feature_full'),
    // A broad legacy role permission must no longer override an explicit
    // per-user selection once the Level 1 configuration marker exists.
    new UserPermissions2(UserPermissions::CRM, 'read'),
]);

assertModuleAccess($explicit->hasPermissionForModule('storage'), 'Explicit Storage access was not granted.');
assertModuleAccess($explicit->hasPermissionForModule('crm_sponsors'), 'Explicit Sponsors CRM access was not granted.');
assertModuleAccess(!$explicit->hasPermissionForModule('crm_clients'), 'Client CRM leaked through a legacy role permission.');
assertModuleAccess(!$explicit->hasPermissionForModule('orders'), 'Unselected Orders access was granted.');
assertModuleAccess(User::hasPermission($explicit, 'storage', 'delete'), 'Full module access did not cover write actions.');

$legacy = makeLevel4User([
    new UserPermissions2(UserPermissions::CRM, 'read'),
    new UserPermissions2(UserPermissions::ORDERS, 'edit'),
]);

assertModuleAccess($legacy->hasPermissionForModule('crm_clients'), 'Legacy CRM compatibility was removed.');
assertModuleAccess($legacy->hasPermissionForModule('crm_sponsors'), 'Legacy Sponsors CRM compatibility was removed.');
assertModuleAccess($legacy->hasPermissionForModule('orders'), 'Legacy Orders compatibility was removed.');

$requestTemplate = file_get_contents(dirname(__DIR__) . '/src/views/panel/level1/home/_event_requests.twig');
$requestController = file_get_contents(dirname(__DIR__) . '/src/views/panel/level1/home/index.php');
foreach (['request.address', 'request.lead_intelligence', 'eventRequestsQa', 'QA tests', 'eventRequestsCsrfToken'] as $needle) {
    assertModuleAccess(str_contains($requestTemplate, $needle), "Dashboard request UI is missing {$needle}.");
}
foreach (['zip_classification', 'qualification_status', "'Google click ID'", 'CSRF::validateCSRF()', 'eventRequestsQa'] as $needle) {
    assertModuleAccess(str_contains($requestController, $needle), "Dashboard request controller is missing {$needle}.");
}

$storageTemplate = file_get_contents(dirname(__DIR__) . '/src/views/panel/level4/planner-hub/management/storage/containers/items/index.twig');
assertModuleAccess(str_contains($storageTemplate, 'Print Container Tag'), 'Container detail is missing its print control.');
assertModuleAccess(str_contains($storageTemplate, 'Add New Item'), 'Container detail is missing item management.');

$privateModuleNav = file_get_contents(dirname(__DIR__) . '/src/views/templates/components/private-module-nav.twig');
assertModuleAccess(str_contains($privateModuleNav, "hasPermissionForModule('crm_clients')"), 'Context navigation does not isolate Client CRM access.');
assertModuleAccess(str_contains($privateModuleNav, "hasPermissionForModule('crm_sponsors')"), 'Context navigation does not isolate Sponsors CRM access.');

$twig = new Environment(new FilesystemLoader(dirname(__DIR__) . '/src/views'), [
    'cache' => false,
    'strict_variables' => false,
]);
foreach (['path', 'asset_for', 'trans'] as $function) {
    $twig->addFunction(new TwigFunction($function, static fn (...$arguments) => (string)($arguments[0] ?? '')));
}
$twig->addFilter(new TwigFilter('json_decode', static fn ($value) => is_string($value) ? (json_decode($value, true) ?: []) : []));
foreach ([
    'panel/level1/home/_event_requests.twig',
    'panel/level1/planner-hub/management/users/create/index.twig',
    'panel/level1/planner-hub/management/users/edit/index.twig',
    'panel/level4/planner-hub/management/storage/containers/items/index.twig',
    'templates/layout/sidebars/4.twig',
    'templates/components/private-module-nav.twig',
] as $template) {
    $twig->load($template);
}

echo "Management module access and container QR contracts passed.\n";
