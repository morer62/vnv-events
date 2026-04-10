<?php

namespace App\Services;

use App\Repositories\OrdersServicesAssignedRepository;
use App\Repositories\OrdersServiceRepository;
use App\Repositories\TipsRepository;

class OrderCalculatorService
{
    public static function calculateTotal($order): array
    {
        $assignedRepo = new OrdersServicesAssignedRepository();
        $serviceRepo = new OrdersServiceRepository();

        $assignedItems = $assignedRepo->getAllBy(["id_order" => $order->id]);
        $subtotal = 0;

        foreach ($assignedItems as $item) {
            $service = $serviceRepo->getOne(["id" => $item->id_service]);
            if ($service) {
                // Usar el precio histórico almacenado (unit_price) si existe
                if (isset($item->unit_price) && $item->unit_price > 0) {
                    $unitPrice = $item->unit_price;
                } else {
                    // Fallback para órdenes antiguas que no tienen unit_price
                    $unitPrice = ($item->is_variable === 'YES' && $item->variable_price !== null) 
                        ? $item->variable_price 
                        : $service->price;
                }
                
                $subtotal += $item->quantity * $unitPrice;
            }
        }

        $discountValue = $order->discount_value ?? 0;
        $discountType = $order->discount_type ?? 'amount';
        $taxPercent = $order->tax_percentage ?? 0;

        $discountAmount = ($discountType === 'percentage')
            ? ($subtotal * $discountValue / 100)
            : $discountValue;

        $base = $subtotal - $discountAmount;
        $taxAmount = $base * ($taxPercent / 100);
        
        $tipAmount = 0;
        if (!empty($order->id_tip)) {
            $tipsRepo = new TipsRepository();
            $tip = $tipsRepo->getOne(["id" => $order->id_tip]);
            if ($tip && $tip->is_active == 1) {
                $tipAmount = $base * ($tip->percentage / 100);
            }
        }
        
        $total = $base + $taxAmount + $tipAmount;

        return [
            "subtotal" => $subtotal,
            "discount" => $discountAmount,
            "tax" => $taxAmount,
            "tip" => $tipAmount,
            "total" => $total,
        ];
    }
}
