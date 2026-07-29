<?php

use App\Repositories\AiAgentMediaRepository;
use App\Services\AiVideoIngestService;
use App\Services\AiVideoProxyService;
use App\Services\LoginService;
use App\Utils\Router;

$router = new Router();
$router->get(function (): never {
    $session = LoginService::getSession();
    $owner = (int)$session->getOwner();
    $job = (new AiAgentMediaRepository())->find($owner, max(0, (int)($_GET['id'] ?? 0)));
    if (!$job) {
        http_response_code(404);
        exit('Media project not found.');
    }

    $proxyRequested=!empty($_GET['proxy']);$assetRelative=trim((string)($_GET['asset']??''));
    $proxyPath=(new AiVideoProxyService())->path($owner,(int)$job->id);
    $assetMime='';
    if($assetRelative!==''){
        $inventory=(new AiVideoIngestService())->inventoryForSource($owner,(string)$job->source_url);$match=null;
        foreach($inventory as $file)if(hash_equals((string)$file['relative_path'],$assetRelative)){$match=$file;break;}
        if(!$match){http_response_code(404);exit('Project asset not found.');}
        $path=(new AiVideoIngestService())->localPath((string)$match['url']);$assetMime=(string)$match['mime_type'];
    }else $path=$proxyRequested&&is_file($proxyPath)?$proxyPath:(new AiVideoIngestService())->localPath((string)$job->source_url);
    if (!$path || !is_file($path) || !is_readable($path)) {
        http_response_code(404);
        exit('Private media is unavailable.');
    }

    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    @set_time_limit(0);
    while (ob_get_level()) ob_end_clean();

    $size = filesize($path);
    $start = 0;
    $end = $size - 1;
    $status = 200;
    $range = (string)($_SERVER['HTTP_RANGE'] ?? '');
    if ($range !== '' && preg_match('/bytes=(\d*)-(\d*)/', $range, $matches)) {
        if ($matches[1] !== '') $start = min($end, (int)$matches[1]);
        if ($matches[2] !== '') $end = min($end, (int)$matches[2]);
        if ($end < $start) {
            http_response_code(416);
            header('Content-Range: bytes */'.$size);
            exit;
        }
        $status = 206;
    }

    http_response_code($status);
    header('Content-Type: '.($assetMime!==''?$assetMime:($proxyRequested&&$path===$proxyPath?'video/mp4':((string)$job->mime_type ?: 'application/octet-stream'))));
    header('Content-Disposition: inline; filename="'.str_replace('"', '', basename($path)).'"');
    header('Accept-Ranges: bytes');
    header('Content-Length: '.($end - $start + 1));
    if ($status === 206) header("Content-Range: bytes {$start}-{$end}/{$size}");
    if($proxyRequested&&$path===$proxyPath){
        header('Cache-Control: private, max-age=86400, immutable');
        header('ETag: "proxy-'.(int)filemtime($path).'-'.$size.'"');
    }else header('Cache-Control: private, no-store, max-age=0');

    $handle = fopen($path, 'rb');
    if (!$handle) {
        http_response_code(500);
        exit;
    }
    fseek($handle, $start);
    $remaining = $end - $start + 1;
    while ($remaining > 0 && !feof($handle) && !connection_aborted()) {
        $chunk = fread($handle, min(1024 * 1024, $remaining));
        if ($chunk === false || $chunk === '') break;
        echo $chunk;
        flush();
        $remaining -= strlen($chunk);
    }
    fclose($handle);
    exit;
});
$router->run();
