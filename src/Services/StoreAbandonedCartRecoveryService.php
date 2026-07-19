<?php

namespace App\Services;

use App\Repositories\StoreCartsRepository;
use App\Utils\LocationUtils;

class StoreAbandonedCartRecoveryService
{
    public static function processPending(int $limit = 5, int $minutes = 30): array
    {
        $cartsRepo = new StoreCartsRepository();
        $emailService = new EmailService();

        $carts = $cartsRepo->getPendingRecoveryCarts($limit, $minutes);

        $sent = 0;
        $failed = 0;

        foreach ($carts as $cart) {
            try {
                if (empty($cart->guest_email) || empty($cart->recovery_token)) {
                    $failed++;
                    continue;
                }

                $appUrl = rtrim($_ENV['APP_URL'] ?? '', '/');
                $recoveryUrl = $appUrl . '/store/checkout?recovery=' . urlencode($cart->recovery_token);

                $templateData = [
                    'clientName' => $cart->guest_name ?: 'there',
                    'recoveryUrl' => $recoveryUrl,
                    'mealsCount' => (int)($cart->meals_count ?? 0),
                    'total' => number_format((float)($cart->total ?? 0), 2, '.', ''),
                    'companyName' => 'VNV Events'
                ];

                $templatePath = LocationUtils::getTemplatePath("emails/abandoned_cart.php");

                $result = $emailService->sendTemplateEmail(
                    $cart->guest_email,
                    'Your meal plan is waiting for you 🍽️',
                    $templatePath,
                    $templateData
                );

                if ($result) {
                    $cartsRepo->markAsAbandonedAndRecoverySent((int)$cart->id);
                    $sent++;
                } else {
                    $failed++;
                }
            } catch (\Exception $e) {
                error_log('Abandoned cart recovery error: ' . $e->getMessage());
                $failed++;
            }
        }

        return [
            'success' => true,
            'processed' => count($carts),
            'sent' => $sent,
            'failed' => $failed
        ];
    }
}
