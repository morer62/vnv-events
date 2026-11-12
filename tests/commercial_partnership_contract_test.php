<?php

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Repositories\CommercialPartnershipRepository;
use App\Repositories\Connection;

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$root = dirname(__DIR__);
Dotenv\Dotenv::createImmutable($root)->safeLoad();
$requiredFiles = [
    'src/views/public/pages/nonprofit-partnerships/index.php',
    'src/views/public/pages/nonprofit-partnerships/index.twig',
    'src/views/public/pages/sponsor-activation-production/index.php',
    'src/views/public/pages/sponsor-activation-production/index.twig',
    'src/views/api/public/partnership-request/index.php',
    'src/Repositories/CommercialPartnershipRepository.php',
    'src/Services/CommercialFinanceService.php',
    'src/views/panel/level1/planner-hub/management/crm/partnerships/index.php',
];
foreach ($requiredFiles as $file) $assert(is_file($root . '/' . $file), 'Missing implementation file: ' . $file);

$nonprofit = file_get_contents($root . '/src/views/public/pages/nonprofit-partnerships/index.twig');
foreach (['Your Mission. Our Expertise.', 'Apply for Nonprofit Support', 'up to 60%', 'up to 80%', 'does not guarantee approval', 'requested_services[]', 'application'] as $needle) {
    $assert(stripos($nonprofit, $needle) !== false, 'Nonprofit landing is missing: ' . $needle);
}
$assert(!preg_match('/guaranteed\s+(?:sponsorship|funding|approval)/i', $nonprofit), 'Nonprofit page contains a prohibited guarantee.');

$activation = file_get_contents($root . '/src/views/public/pages/sponsor-activation-production/index.twig');
foreach (['Sponsor Activation Production in South Florida', 'Request an Activation Proposal', 'Brief. Design. Approval. Build. Live. Recap.', 'does not guarantee availability'] as $needle) {
    $assert(stripos($activation, $needle) !== false, 'Activation landing is missing: ' . $needle);
}
foreach (['$2,500', '$5,000', '$10,000', '$2500', '$5000', '$10000'] as $price) {
    $assert(!str_contains($activation, $price), 'Internal activation price leaked publicly: ' . $price);
}

$header = file_get_contents($root . '/src/views/templates/layout/publicHeaderVnv.html.twig');
foreach (['Full Production', 'Nonprofit Partnerships', 'Corporate &amp; Brand Experiences', 'Sponsor Activation Production', 'Original Experiences', 'vnvx-mobile-production'] as $needle) {
    $assert(str_contains($header, $needle), 'Public navigation is missing: ' . $needle);
}

$home = file_get_contents($root . '/src/views/public/app/planner-hub/index.twig');
foreach (['Event Planning, Production &amp;', 'Plan My Event', 'Explore Production', 'Now Accepting Partners', 'Planning for March 2027', 'Upcoming Original Experiences'] as $needle) {
    $assert(str_contains($home, $needle), 'Home contract is missing: ' . $needle);
}

$originals = file_get_contents($root . '/src/views/public/pages/vnv-events-productions/index.twig');
foreach (['Retro Glam', 'Mommy &amp; Daddy Break', 'does not provide or promise childcare', 'Join Priority List'] as $needle) {
    $assert(str_contains($originals, $needle), 'Original Experiences page is missing: ' . $needle);
}
foreach (['Vanguard Business Gala', 'Flavor Fusion Fest', 'Get Tickets'] as $needle) {
    $assert(!str_contains($originals, $needle), 'Archived/ticketing copy remains visible: ' . $needle);
}

$endpoint = file_get_contents($root . '/src/views/api/public/partnership-request/index.php');
foreach (['hash_equals', 'website_confirm', 'CommercialPartnershipRepository', 'LeadIntakeRepository', 'consent', 'sendSimpleEmail'] as $needle) {
    $assert(str_contains($endpoint, $needle), 'Partnership endpoint is missing: ' . $needle);
}

$stages = CommercialPartnershipRepository::allowedStages();
$assert($stages['NONPROFIT_APPLICATION'] === ['RECEIVED', 'UNDER_REVIEW', 'APPROVED', 'ACTIVE', 'CLOSED'], 'Nonprofit pipeline stages changed.');
$assert($stages['ACTIVATION'] === ['BRIEF', 'DESIGN', 'APPROVED', 'BUILD', 'LIVE', 'RECAP'], 'Activation pipeline stages changed.');
$assert($stages['CORPORATE'] === ['LEAD', 'DISCOVERY', 'PROPOSAL', 'NEGOTIATION', 'WON', 'LOST'], 'Corporate pipeline stages changed.');

$eventRequest = file_get_contents($root . '/src/views/api/public/event-request/index.php');
$assert(str_contains($eventRequest, "formSource === 'corporate_events'"), 'Corporate event requests are not synced to the commercial pipeline.');
$assert(str_contains($eventRequest, 'contact_consent'), 'The existing event request flow is missing explicit contact consent.');
$sponsorRequest = file_get_contents($root . '/src/views/api/public/sponsor-request/index.php');
$assert(str_contains($sponsorRequest, "'SPONSORSHIP'"), 'Sponsor requests are not connected to the sponsorship pipeline.');
$assert(str_contains($sponsorRequest, 'contact_consent'), 'Sponsor requests are missing explicit contact consent.');
$crmController = file_get_contents($root . '/src/views/panel/level1/planner-hub/management/crm/partnerships/index.php');
$assert(str_contains($crmController, "in_array((int)\$user->getLevel(), [1, 6], true)"), 'Sensitive finance controls are not restricted to Direction.');
$assert(str_contains($crmController, "\$filters['assigned_user_id']"), 'Team pipeline access is not limited to assigned opportunities.');
$assert(str_contains($crmController, "'approve_commission'"), 'Commission approval workflow is missing.');

$db = new Connection();
foreach (['commercial_organizations', 'commercial_contacts', 'commercial_opportunities', 'commercial_support_grants', 'commercial_activation_orders', 'commercial_consents', 'commercial_commissions', 'commercial_audit_logs'] as $table) {
    $db->query('SHOW TABLES LIKE :table');
    $db->bind(':table', $table);
    $assert((bool)$db->fetchOne(), 'Local CRM schema is missing table: ' . $table);
}

$seoGenerator = file_get_contents($root . '/src/Services/SeoFilesGeneratorService.php');
$assert(substr_count($seoGenerator, "'/nonprofit-partnerships/'") >= 2, 'Nonprofit landing is not included in sitemap collection and SEO priority.');
$assert(substr_count($seoGenerator, "'/sponsor-activation-production/'") >= 2, 'Sponsor activation landing is not included in sitemap collection and SEO priority.');
$assert(str_contains($seoGenerator, '/vnv-events-productions/vanguard-business-gala/'), 'Archived Vanguard SEO exclusion is missing.');
$assert(str_contains($seoGenerator, '/vnv-events-productions/flavor-fusion-fest/'), 'Archived Flavor Fusion SEO exclusion is missing.');
$vanguardController = file_get_contents($root . '/src/views/public/pages/vnv-events-productions/vanguard-business-gala/index.php');
$flavorController = file_get_contents($root . '/src/views/public/pages/vnv-events-productions/flavor-fusion-fest/index.php');
$assert(str_contains($vanguardController, ', true, 301)'), 'Archived Vanguard route does not redirect permanently.');
$assert(str_contains($flavorController, ', true, 301)'), 'Archived Flavor Fusion route does not redirect permanently.');

echo "Commercial partnership contract OK: {$assertions} assertions\n";
