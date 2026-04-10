<?php

namespace App\Services;

use App\Services\StripeServiceV2;

class PaymentCardExtractor
{
    public static function extractCardDetails($paymentIntent, StripeServiceV2 $stripeService, string $accountId): array
    {
        $cardBrand = null;
        $cardLast4 = null;
        $cardExpMonth = null;
        $cardExpYear = null;
        
        try {
            $paymentMethodObj = null;
            
            if (isset($paymentIntent->latest_charge) && is_object($paymentIntent->latest_charge)) {
                if (is_object($paymentIntent->latest_charge->payment_method) && isset($paymentIntent->latest_charge->payment_method->card)) {
                    $paymentMethodObj = $paymentIntent->latest_charge->payment_method;
                } 
                elseif (is_string($paymentIntent->latest_charge->payment_method)) {
                    $paymentMethodObj = $stripeService->getPaymentMethod($paymentIntent->latest_charge->payment_method, $accountId);
                }
                elseif (isset($paymentIntent->latest_charge->payment_method_details) && isset($paymentIntent->latest_charge->payment_method_details->card)) {
                    $paymentMethodObj = (object)[
                        'card' => $paymentIntent->latest_charge->payment_method_details->card
                    ];
                }
            }
            
            if (!$paymentMethodObj) {
                if (is_object($paymentIntent->payment_method) && isset($paymentIntent->payment_method->card)) {
                    $paymentMethodObj = $paymentIntent->payment_method;
                } 
                elseif (is_string($paymentIntent->payment_method)) {
                    $paymentMethodObj = $stripeService->getPaymentMethod($paymentIntent->payment_method, $accountId);
                }
            }
            
            if ($paymentMethodObj && isset($paymentMethodObj->card)) {
                $cardBrand = ucfirst($paymentMethodObj->card->brand ?? '');
                $cardLast4 = $paymentMethodObj->card->last4 ?? null;
                $cardExpMonth = $paymentMethodObj->card->exp_month ?? null;
                $cardExpYear = $paymentMethodObj->card->exp_year ?? null;
            }
        } catch (\Exception $e) {
        }
        
        return [
            'brand' => $cardBrand,
            'last4' => $cardLast4,
            'exp_month' => $cardExpMonth,
            'exp_year' => $cardExpYear
        ];
    }
}


