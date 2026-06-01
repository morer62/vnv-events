<?php

use App\Services\LoginService;
use App\Utils\Router;
use App\Repositories\UserRepository;
use App\Services\EmailService;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;

$router = new Router();

$router->post(function () {
    $user = LoginService::getSession();
    
    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    
    // Validate required fields
    $orderId = $input['order_id'] ?? null;
    $clientId = $input['client_id'] ?? null;
    $subject = $input['subject'] ?? null;
    $body = $input['body'] ?? null;
    
    if (!$orderId || !$clientId || !$subject || !$body) {
        error_log("Missing fields - OrderId: $orderId, ClientId: $clientId, Subject: $subject, Body: " . (empty($body) ? 'empty' : 'present'));
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing required fields: ' . json_encode($input)]);
        return;
    }
    
    try {
        // Get client information
        $userRepo = new UserRepository();
        $client = $userRepo->getOne(['id' => $clientId]);
        
        if (!$client || !$client->email) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Client not found or no email address']);
            return;
        }
        
        // Send email
        $emailService = new EmailService();
        
        // Create email template data
        $templateData = [
            'clientName' => $client->name . ' ' . $client->lastname,
            'orderId' => $orderId,
            'subject' => $subject,
            'body' => $body,
            'companyName' => 'VNV Events'
        ];
        
        // Get template path
        $templatePath = \App\Utils\LocationUtils::getTemplatePath("emails/client_communication.php");
        
        $result = $emailService->sendTemplateEmail(
            $client->email,
            $subject,
            $templatePath,
            $templateData
        );
        
        if ($result) {
            echo json_encode(['success' => true, 'message' => 'Email sent successfully']);
        } else {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Failed to send email: ' . $emailService->getDebugInfo()]);
        }
        
    } catch (\Exception $e) {
        error_log("Email sending error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
    }
});

$router->run();
