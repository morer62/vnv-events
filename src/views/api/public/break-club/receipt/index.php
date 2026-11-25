<?php

use App\Services\BreakClubService;
use App\Utils\SiteContext;
use App\Utils\TemplateResponse;

header('Cache-Control: private, no-store');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');
if (SiteContext::siteKey() !== 'vnvevents') { http_response_code(404); return; }
if ($_SERVER['REQUEST_METHOD'] !== 'GET') { header('Allow: GET'); http_response_code(405); return; }
try {
    $id = $_GET['session_id'] ?? '';
    $status = (new BreakClubService())->receipt(is_string($id) ? $id : '');
    $view = ['purpose'=>'RECEIPT','status'=>$status];
} catch (DomainException $error) {
    http_response_code(422);
    $view = ['purpose'=>'ERROR','message'=>$error->getMessage()];
} catch (Throwable $error) {
    http_response_code(503);
    error_log('Break Club receipt lookup failed (' . get_class($error) . ').');
    $view = ['purpose'=>'ERROR','message'=>'Your payment status is being verified. Please refresh this page shortly.'];
}
echo TemplateResponse::render(dirname(__DIR__) . '/access/index.twig', $view);
