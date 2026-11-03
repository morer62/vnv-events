<?php
use App\Utils\Router;
use App\Utils\TemplateResponse;
use App\Services\EmailService;
use App\Utils\SiteContext;

$router = new Router();

$router->get(function () {
    return TemplateResponse::render(__DIR__ . "/index.twig", ["sent" => false, "error" => false]);
});

$router->post(function () {
    $name = trim($_POST["name"] ?? '');
    $email = trim($_POST["email"] ?? '');
    $message = trim($_POST["message"] ?? '');

    if (!$name || !filter_var($email, FILTER_VALIDATE_EMAIL) || !$message) {
        return TemplateResponse::render(__DIR__ . "/index.twig", ["sent" => false, "error" => true]);
    }

    $to = "info@vnvevents.com";
    $subject = "Support Request from VNV Events";
    $body = "Name: $name\nEmail: $email\n\nMessage:\n$message\n";

    try {
        $sent = (new EmailService(SiteContext::businessOwnerId()))
            ->sendSimpleEmail($to, $subject, $body, false);
    } catch (\Throwable $exception) {
        error_log('Support request email failed: ' . $exception->getMessage());
        $sent = false;
    }

    return TemplateResponse::render(__DIR__ . "/index.twig", ["sent" => $sent, "error" => !$sent]);
});

try {
    $router->run();
} catch (Exception $e) {
    echo $e->getMessage();
}
