<?php
use App\Utils\Router;
use App\Utils\TemplateResponse;

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
    $headers = "From: $email\r\nReply-To: $email\r\nContent-Type: text/plain; charset=utf-8";
    $body = "Name: $name\nEmail: $email\n\nMessage:\n$message\n";

    @mail($to, $subject, $body, $headers);

    return TemplateResponse::render(__DIR__ . "/index.twig", ["sent" => true, "error" => false]);
});

try {
    $router->run();
} catch (Exception $e) {
    echo $e->getMessage();
}
