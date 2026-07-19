<?php

use App\Repositories\StoreCartsRepository;
use App\Services\EmailService;
use App\Services\LoginService;
use App\Utils\Router;

$router = new Router();

$router->post(function () {
    header('Content-Type: application/json');

    $user = LoginService::getSession();
    if (!$user) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'message' => 'Unauthorized'
        ]);
        return;
    }

    $input = json_decode(file_get_contents('php://input'), true);

    $cartId = intval($input['cart_id'] ?? 0);

    if ($cartId <= 0) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Invalid cart id'
        ]);
        return;
    }

    try {
        $cartsRepo = new StoreCartsRepository();
        $cart = $cartsRepo->getDetailedCart($cartId);

        if (!$cart) {
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'message' => 'Cart not found'
            ]);
            return;
        }

        if (empty($cart->guest_email)) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Cart has no email address'
            ]);
            return;
        }

        if (empty($cart->recovery_token)) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Cart has no recovery token'
            ]);
            return;
        }

        $appUrl = rtrim($_ENV['APP_URL'] ?? '', '/');
        $recoveryUrl = $appUrl . '/store/checkout?recovery=' . urlencode($cart->recovery_token);

        $emailService = new EmailService();

        $templateData = [
            'clientName' => $cart->guest_name ?: 'there',
            'recoveryUrl' => $recoveryUrl,
            'mealsCount' => (int)($cart->meals_count ?? 0),
            'total' => number_format((float)($cart->total ?? 0), 2, '.', ''),
            'companyName' => 'VNV Events'
        ];

        $templatePath = \App\Utils\LocationUtils::getTemplatePath("emails/abandoned_cart.php");

        $subject = 'Your meal plan is waiting for you 🍽️';

        $result = $emailService->sendTemplateEmail(
            $cart->guest_email,
            $subject,
            $templatePath,
            $templateData
        );

        if (!$result) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'Failed to send email: ' . $emailService->getDebugInfo()
            ]);
            return;
        }

        $cartsRepo->markRecoveryEmailSent($cartId);

        echo json_encode([
            'success' => true,
            'message' => 'Recovery email sent successfully'
        ]);
    } catch (\Exception $e) {
        error_log('Store recovery email error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Server error: ' . $e->getMessage()
        ]);
    }
});

$router->run();
