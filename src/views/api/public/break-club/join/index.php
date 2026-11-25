<?php

use App\Services\BreakClubService;
use App\Services\GourmetLeadRateLimiter;
use App\Utils\LocationUtils;
use App\Utils\SiteContext;

header('Cache-Control: private, no-store');
if (SiteContext::siteKey() !== 'vnvevents') { http_response_code(404); return; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Allow: POST'); http_response_code(405); return; }

try {
    BreakClubService::requireCsrf($_POST['_token'] ?? null);
    if (empty($_POST['website_confirm'])) {
        if ((int)($_SESSION['break_club_next_submit'] ?? 0) > time()) {
            throw new DomainException('Please wait a few seconds before trying again.');
        }
        $rate = (new GourmetLeadRateLimiter())->consume(SiteContext::businessOwnerId(), 'vnv_break_club');
        if (!$rate['allowed']) {
            throw new DomainException('Please try again later. Too many requests have been received.');
        }
        $_SESSION['break_club_next_submit'] = time() + 10;
        (new BreakClubService())->join($_POST);
    }
    $_SESSION['break_club_received'] = true;
    LocationUtils::redirectInternal(BreakClubService::LANDING . '?club=received#waitlist');
} catch (DomainException $error) {
    $_SESSION['break_club_error'] = $error->getMessage();
} catch (Throwable $error) {
    error_log('Break Club waitlist request failed (' . get_class($error) . ').');
    $_SESSION['break_club_error'] = 'We could not send your confirmation. Please try again.';
}
LocationUtils::redirectInternal(BreakClubService::LANDING . '#waitlist');
