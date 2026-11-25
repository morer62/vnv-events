<?php

use App\Services\BreakClubService;
use App\Services\LoginService;
use App\Utils\LocationUtils;
use App\Utils\SiteContext;
use App\Utils\TemplateResponse;

header('Cache-Control: private, no-store');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');
if (SiteContext::siteKey() !== 'vnvevents') { http_response_code(404); return; }
if (!in_array($_SERVER['REQUEST_METHOD'], ['POST','GET'], true)) { header('Allow: GET, POST'); http_response_code(405); return; }

$tokenInput = $_POST['token'] ?? $_GET['token'] ?? '';
$token = is_string($tokenInput) ? $tokenInput : '';
$view = ['csrf'=>BreakClubService::csrfToken(), 'token'=>$token];
try {
    $service = new BreakClubService();
    $details = $service->linkDetails($token);
    $view += $details;
    $user = LoginService::getSession();
    if ($details['purpose'] === 'CLAIM' && (!$user || !BreakClubService::hasVerifiedLogin($user))) {
        $view['purpose'] = 'SIGN_IN';
        $view['target'] = 'claim';
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        BreakClubService::requireCsrf($_POST['_token'] ?? null);
        $outcome = $service->useLink($token, $user);
        if ($details['purpose'] === 'INVITE') {
            // Destination comes exclusively from our Stripe SDK or the configured receipt route.
            LocationUtils::redirectTo($outcome);
        }
        $view['purpose'] = strtoupper($outcome);
        $view['token'] = '';
    }
} catch (DomainException $error) {
    http_response_code(422);
    $view['purpose'] = 'ERROR';
    $view['message'] = $error->getMessage();
} catch (Throwable $error) {
    http_response_code(503);
    error_log('Break Club link failed (' . get_class($error) . ').');
    $view['purpose'] = 'ERROR';
    $view['message'] = 'We could not complete this request. Please try again.';
}
echo TemplateResponse::render(__DIR__ . '/index.twig', $view);
