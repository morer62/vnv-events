<?php

declare(strict_types=1);

use App\Entity\User;
use App\Services\BreakClubPanel;
use App\Services\BreakClubService;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

$root = dirname(__DIR__);
$loader = require $root . '/vendor/autoload.php';
$loader->addPsr4('App\\', $root . '/src', true);
$twig = new Environment(new ChainLoader([
    new ArrayLoader(['templates/base.admin.twig' => '{% block body %}{% endblock %}']),
    new FilesystemLoader($root . '/src/views'),
]));
foreach (['path','asset_for'] as $helper) {
    $twig->addFunction(new TwigFunction($helper, static fn(string $path, array $params = []): string =>
        '/' . $path . ($params ? '?' . http_build_query($params) : '')));
}

function checkClubPanel(bool $condition, string $error): void
{
    if (!$condition) {
        throw new RuntimeException($error);
    }
}

$_ENV['SITE_KEY'] = 'vnvevents';
$_ENV['SITE_BUSINESS_USER_ID'] = '2';
$user = (new ReflectionClass(User::class))->newInstanceWithoutConstructor();
$user->setId('2');
$user->setIdOwner(2);
$user->setLevel(1);
$token = BreakClubPanel::token($user);
checkClubPanel(BreakClubPanel::showNavigation($user), 'VNV owner needs access to the hub.');
checkClubPanel($token === BreakClubPanel::token($user), 'Forms on the same screen must share a valid token.');
checkClubPanel(is_string(BreakClubService::csrfToken()), 'Visiting the panel must not invalidate the public waitlist token.');
$user->setLevel(4);
checkClubPanel(!BreakClubPanel::showNavigation($user) && $token !== BreakClubPanel::token($user), 'Role isolation failed.');
$user->setLevel(5);
checkClubPanel(!BreakClubPanel::showNavigation($user), 'Unverified restored sessions must not reveal membership.');
$_ENV['SITE_KEY'] = 'another-site';
$user->setLevel(1);
checkClubPanel(!BreakClubPanel::inClubTenant($user), 'Another site should not see the club.');
$_ENV['SITE_KEY'] = 'vnvevents';
$user->setIdOwner(1111);
checkClubPanel(!BreakClubPanel::showNavigation($user), 'Another owner should not see the club.');

$event = ['id'=>2,'title'=>'Morning Social','location'=>'Test venue','starts_at'=>'2027-01-12 10:00:00','price_cents'=>2050,'status'=>'PUBLISHED'];
$member = ['id'=>7,'name'=>'Alex','email'=>'alex@example.test','phone'=>'5550009999','party_size'=>2,'companion_name'=>'Guest <unsafe>','companion_email'=>'guest@example.test','status'=>'ACTIVE','category'=>'SOCIAL','user_id'=>15,'utm_source'=>'instagram'];
$registration = $member + ['event_id'=>2,'member_id'=>7];
$registration['id'] = 22;
$registration['status'] = 'PAID';
$registration['paid_at'] = '2027-01-03';
$registration['invited_at'] = '2026-12-21';
$registration['principal_checked_at'] = '2027-01-12';
$registration['paid_cents'] = 4100;
$ops = 'panel/level1/event-productions/break-club/index.twig';
$portal = 'panel/level5/break-club/index.twig';
$html = $twig->render($ops, [
    'token'=>$token,'members'=>[$member],'events'=>[$event],'selected'=>$event,'registrations'=>[$registration],
    'page'=>1,'member_total'=>1,'report'=>['paid_cents'=>4100,'invited_requests'=>1],
    'upcoming'=>true,'invited_members'=>[7=>true],'categories'=>BreakClubService::CATEGORIES,
]);
checkClubPanel(str_contains($html, 'value="COMPANION"') && !str_contains($html, 'value="PRINCIPAL"'), 'Independent guest check-in failed.');
checkClubPanel(str_contains($html, 'Guest &lt;unsafe&gt;') && !str_contains($html, '<unsafe>'), 'Display names must be escaped.');
checkClubPanel(str_contains($html, '$41.00') && str_contains($html, 'Invitation sent'), 'Report total or invitation missing.');
checkClubPanel(str_contains($html, 'pipeline=ORIGINAL_WAITLIST') && str_contains($html, 'q=Mommy+and+Daddy+Break'), 'Legacy leads link lost the filters.');

$invitation = $event + ['party_size'=>2,'registration_status'=>'INVITED'];
$html = $twig->render($portal, ['member'=>$member,'events'=>[$invitation],'token'=>$token]);
checkClubPanel(str_contains($html,'Get 2 tickets · $41.00') && str_contains($html,'name="action" value="checkout"'), 'Invited member checkout must show the party total.');
$html = $twig->render($portal, ['member'=>$member,'events'=>[$event],'token'=>$token]);
checkClubPanel(!str_contains($html,'name="action" value="checkout"'), 'Only invited members can start checkout.');
$html = $twig->render($portal, ['member'=>null,'events'=>[],'token'=>$token,'user'=>['email'=>'alex@example.test']]);
checkClubPanel(str_contains($html, 'verification link') && !str_contains($html, 'Test venue'), 'An unlinked account should only see the claim workflow.');
echo "Break Club dashboard, guest check-in, navigation, eligibility and escaped rendering passed.\n";
