<?php

use App\Utils\TemplateResponse;

// Incluir notificaciones globalmente para todas las páginas de administrador
include __DIR__ . '/notifications-global.php';

$delimiter = '<div class="container-fluid p-0" id="content">';
$includeView = $_SESSION["includeView"];

// Pasar las notificaciones a Twig
$notifications = $GLOBALS['notifications'] ?? [];
$notifications_count = $GLOBALS['notifications_count'] ?? 0;

$data = TemplateResponse::renderInTemplates('base.admin.twig', [
    'notifications' => $notifications,
    'notifications_count' => $notifications_count,
    'app_url' => $_ENV['APP_URL']
]);
[$bodyStart, $bodyEnd] = explode($delimiter, $data, 2);

echo "$bodyStart $delimiter";
include $includeView;
echo $bodyEnd;

$_SESSION["includeView"] = null;
