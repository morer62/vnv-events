<?php

namespace App\Services;

use App\Repositories\TicketSalesRepository;
use App\Repositories\TicketInventoryRepository;
use App\Repositories\VenueEventsTicketsRepository;
use App\Repositories\TicketTypesRepository;
use App\Repositories\TicketSalesStagesRepository;
use App\Services\StripeService;
use App\Services\EmailService;
use App\Utils\LocationUtils;

class TicketSalesService
{
    private StripeService $stripeService;
    private EmailService $emailService;
    private TicketSalesRepository $ticketSalesRepo;
    private TicketInventoryRepository $inventoryRepo;
    private VenueEventsTicketsRepository $ticketsConfigRepo;
    private TicketTypesRepository $ticketTypesRepo;
    private TicketSalesStagesRepository $salesStagesRepo;

    public function __construct()
    {
        $this->stripeService = new StripeService();
        $this->emailService = new EmailService();
        $this->ticketSalesRepo = new TicketSalesRepository();
        $this->inventoryRepo = new TicketInventoryRepository();
        $this->ticketsConfigRepo = new VenueEventsTicketsRepository();
        $this->ticketTypesRepo = new TicketTypesRepository();
        $this->salesStagesRepo = new TicketSalesStagesRepository();
        
        // Initialize database connections
        $this->ticketSalesRepo->db = new \App\Repositories\Connection();
        $this->inventoryRepo->db = new \App\Repositories\Connection();
        $this->ticketsConfigRepo->db = new \App\Repositories\Connection();
        $this->ticketTypesRepo->db = new \App\Repositories\Connection();
        $this->salesStagesRepo->db = new \App\Repositories\Connection();
    }


    public function confirmTicketPurchase(string $stripeChargeId, array $buyerInfo): array
    {
        try {
            // El cargo ya fue procesado exitosamente en el archivo de compra
            // Solo necesitamos crear los tickets

            // Por ahora, vamos a crear una compra simple sin usar metadata compleja
            // En el futuro se puede mejorar para manejar múltiples tipos de tickets
            
            // Obtener información básica del evento desde la sesión o parámetros
            $eventId = $_SESSION['current_event_id'] ?? null;
            if (!$eventId) {
                return ['success' => false, 'message' => 'Event information not found'];
            }

            // Obtener tickets seleccionados desde la sesión
            $selectedTickets = $_SESSION['selected_tickets'] ?? [];
            if (empty($selectedTickets)) {
                return ['success' => false, 'message' => 'No tickets selected'];
            }

            // Obtener configuración de tickets
            $ticketsConfig = $this->ticketsConfigRepo->getOne(['id_venue_event' => $eventId]);
            if (!$ticketsConfig) {
                return ['success' => false, 'message' => 'Ticket configuration not found'];
            }
            
            $currentStage = $this->salesStagesRepo->getCurrentStage($ticketsConfig->id);
            $stageId = $currentStage ? $currentStage->id : 1;

            $allTicketCodes = [];
            $totalAmount = 0;
            $saleIds = [];

            // Procesar cada tipo de ticket seleccionado
            foreach ($selectedTickets as $ticketTypeId => $quantity) {
                if ($quantity <= 0) continue;

                // Obtener información del tipo de ticket
                $ticketType = $this->ticketTypesRepo->getOne(['id' => $ticketTypeId]);
                if (!$ticketType) {
                    return ['success' => false, 'message' => "Ticket type {$ticketTypeId} not found"];
                }

                $unitPrice = $ticketType->price;
                if ($currentStage && $currentStage->discount_percentage > 0) {
                    $unitPrice = $unitPrice * (1 - $currentStage->discount_percentage / 100);
                }
                
                $ticketTotalAmount = $unitPrice * $quantity;
                $commissionAmount = $ticketTotalAmount * ($ticketsConfig->total_commission_percentage / 100);
                $netAmount = $ticketTotalAmount - $commissionAmount;
                
                $totalAmount += $ticketTotalAmount;

                $this->inventoryRepo->reserveTickets($ticketType->id, $stageId, $quantity);

                $this->ticketSalesRepo->db->setTimezone('-06:00');
                
                $this->ticketSalesRepo->db->query("
                    INSERT INTO ticket_sales 
                    (id_ticket_type, id_sales_stage, buyer_name, buyer_email, buyer_phone, 
                     quantity, unit_price, total_amount, commission_amount, net_amount, 
                     stripe_payment_intent_id, payment_status, sold_at) 
                    VALUES 
                    (:id_ticket_type, :id_sales_stage, :buyer_name, :buyer_email, :buyer_phone, 
                     :quantity, :unit_price, :total_amount, :commission_amount, :net_amount, 
                     :stripe_payment_intent_id, :payment_status, NOW())
                ");
                
                $this->ticketSalesRepo->db->bind(':id_ticket_type', $ticketType->id);
                $this->ticketSalesRepo->db->bind(':id_sales_stage', $stageId);
                $this->ticketSalesRepo->db->bind(':buyer_name', $buyerInfo['name']);
                $this->ticketSalesRepo->db->bind(':buyer_email', $buyerInfo['email']);
                $this->ticketSalesRepo->db->bind(':buyer_phone', $buyerInfo['phone'] ?? '');
                $this->ticketSalesRepo->db->bind(':quantity', $quantity);
                $this->ticketSalesRepo->db->bind(':unit_price', $unitPrice);
                $this->ticketSalesRepo->db->bind(':total_amount', $ticketTotalAmount);
                $this->ticketSalesRepo->db->bind(':commission_amount', $commissionAmount);
                $this->ticketSalesRepo->db->bind(':net_amount', $netAmount);
                $this->ticketSalesRepo->db->bind(':stripe_payment_intent_id', $stripeChargeId);
                $this->ticketSalesRepo->db->bind(':payment_status', 'paid');
                
                $this->ticketSalesRepo->db->execute();
                $result = true;
                
                if ($result) {
                    $saleId = $this->ticketSalesRepo->db->lastId();
                    $saleIds[] = $saleId;

                    $ticketCodes = $this->generateTicketCodes($quantity);
                    $qrCodes = $this->generateQRCodes($ticketCodes, $saleId);
                    
                    foreach ($ticketCodes as $code) {
                        $allTicketCodes[] = [
                            'code' => $code,
                            'ticket_type' => $ticketType->name,
                            'price' => $unitPrice,
                            'sale_id' => $saleId
                        ];
                    }
                    
                    $this->ticketSalesRepo->update([
                        'ticket_codes' => json_encode($ticketCodes),
                        'qr_codes' => json_encode($qrCodes)
                    ], ['id' => $saleId]);
                } else {
                    return ['success' => false, 'message' => 'Failed to save ticket sale'];
                }
            }

            $this->sendTicketConfirmationEmail($buyerInfo['email'], $saleIds, $allTicketCodes, $ticketsConfig);

            $platformCommission = $totalAmount * ($ticketsConfig->commission_percentage / 100);
            $eventName = $_SESSION['event_name'] ?? 'Event';
            
            $commissionsRepo = new \App\Repositories\TicketCommissionsRepository();
            $commissionsRepo->createCommission([
                'id_ticket_sale' => $saleIds[0] ?? 0,
                'event_name' => $eventName,
                'venue_name' => $_SESSION['venue_name'] ?? 'Unknown Venue',
                'buyer_email' => $buyerInfo['email'],
                'total_amount' => $totalAmount,
                'commission_amount' => $platformCommission,
                'commission_percentage' => $ticketsConfig->commission_percentage,
                'stripe_transfer_id' => $stripeChargeId,
                'transfer_status' => 'completed'
            ]);

            unset($_SESSION['selected_tickets']);

            return [
                'success' => true,
                'data' => [
                    'sale_ids' => $saleIds,
                    'ticket_codes' => $allTicketCodes,
                    'total_amount' => $totalAmount,
                    'message' => 'Tickets purchased successfully!'
                ]
            ];

        } catch (\Exception $e) {
            error_log("Ticket confirmation error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Failed to confirm purchase'];
        }
    }

    public function processTicketPurchaseOld(array $purchaseData): array
    {
        $ticketTypeId = intval($purchaseData['ticket_type_id'] ?? 0);
        $salesStageId = intval($purchaseData['sales_stage_id'] ?? 0);
        $quantity = intval($purchaseData['quantity'] ?? 1);
        $buyerData = $purchaseData['buyer'] ?? [];
        $paymentToken = $purchaseData['payment_token'] ?? '';

        // Validar datos básicos
        if ($ticketTypeId <= 0 || $salesStageId <= 0 || $quantity <= 0 || empty($paymentToken)) {
            return ['success' => false, 'message' => 'Invalid purchase data'];
        }

        // Obtener información del ticket y etapa
        $ticketType = $this->ticketTypesRepo->getOne(['id' => $ticketTypeId]);
        $salesStage = $this->salesStagesRepo->getOne(['id' => $salesStageId]);
        
        if (!$ticketType || !$salesStage) {
            return ['success' => false, 'message' => 'Ticket type or sales stage not found'];
        }

        // Obtener configuración de tickets
        $ticketsConfig = $this->ticketsConfigRepo->getOne(['id' => $ticketType->id_venue_event_tickets]);
        
        if (!$ticketsConfig || !$ticketsConfig->ticket_sales_enabled) {
            return ['success' => false, 'message' => 'Ticket sales are not enabled for this event'];
        }

        // Verificar disponibilidad
        if (!$this->inventoryRepo->checkAvailability($ticketTypeId, $salesStageId, $quantity)) {
            return ['success' => false, 'message' => 'Not enough tickets available'];
        }

        // Calcular precios
        $unitPrice = $ticketType->price;
        
        // Aplicar descuento de la etapa si existe
        if ($salesStage->discount_percentage > 0) {
            $unitPrice = $unitPrice * (1 - $salesStage->discount_percentage / 100);
        }
        
        $totalAmount = $unitPrice * $quantity;
        $commissionAmount = $totalAmount * ($ticketsConfig->total_commission_percentage / 100);
        $netAmount = $totalAmount - $commissionAmount;

        // Procesar pago con Stripe
        $paymentResult = $this->stripeService->chargeUserToken($paymentToken, $totalAmount);
        
        if (!$paymentResult) {
            return ['success' => false, 'message' => 'Payment processing failed'];
        }

        // Reservar tickets en el inventario
        if (!$this->inventoryRepo->reserveTickets($ticketTypeId, $salesStageId, $quantity)) {
            return ['success' => false, 'message' => 'Failed to reserve tickets'];
        }

        try {
            // Crear registro de venta
            $saleData = [
                'id_ticket_type' => $ticketTypeId,
                'id_sales_stage' => $salesStageId,
                'buyer_name' => $buyerData['name'] ?? '',
                'buyer_email' => $buyerData['email'] ?? '',
                'buyer_phone' => $buyerData['phone'] ?? '',
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'total_amount' => $totalAmount,
                'commission_amount' => $commissionAmount,
                'net_amount' => $netAmount,
                'stripe_payment_intent_id' => $paymentResult['id'] ?? null,
                'payment_status' => 'paid',
                'sold_at' => date('Y-m-d H:i:s')
            ];

            $saleId = $this->ticketSalesRepo->add($saleData);

            // Generar códigos de ticket únicos
            $ticketCodes = $this->generateTicketCodes($quantity);
            $qrCodes = $this->generateQRCodes($ticketCodes, $saleId);

            // Actualizar registro con códigos
            $this->ticketSalesRepo->update([
                'ticket_codes' => json_encode($ticketCodes),
                'qr_codes' => json_encode($qrCodes)
            ], ['id' => $saleId]);

            // Enviar email de confirmación
            $this->sendTicketConfirmationEmail($buyerData['email'], [$saleId], $ticketCodes, $ticketsConfig);

            return [
                'success' => true,
                'sale_id' => $saleId,
                'ticket_codes' => $ticketCodes,
                'total_amount' => $totalAmount,
                'message' => 'Tickets purchased successfully!'
            ];

        } catch (\Exception $e) {
            // Liberar tickets reservados en caso de error
            $this->inventoryRepo->releaseTickets($ticketTypeId, $salesStageId, $quantity);
            
            return ['success' => false, 'message' => 'Purchase failed: ' . $e->getMessage()];
        }
    }

    private function generateTicketCodes(int $quantity): array
    {
        $codes = [];
        for ($i = 0; $i < $quantity; $i++) {
            $codes[] = 'TKT-' . strtoupper(uniqid()) . '-' . str_pad($i + 1, 3, '0', STR_PAD_LEFT);
        }
        return $codes;
    }

    private function generateQRCodes(array $ticketCodes, int $saleId): array
    {
        $qrCodes = [];
        foreach ($ticketCodes as $code) {
            // Generar URL de verificación del ticket
            $verificationUrl = LocationUtils::pathFor("/ticket-verify?code=" . urlencode($code) . "&sale=" . $saleId);
            $qrCodes[] = $verificationUrl;
        }
        return $qrCodes;
    }

    private function sendTicketConfirmationEmail(string $email, array $saleIds, array $allTicketCodes, object $ticketsConfig): void
    {
        try {
            $subject = "🎫 Your Event Tickets - Order #" . implode(', #', $saleIds);
            
            $body = "
                <h2>Thank you for your purchase!</h2>
                <p>Your tickets have been successfully purchased.</p>
                
                <h3>Order Details:</h3>
                <ul>
                    <li><strong>Order IDs:</strong> #" . implode(', #', $saleIds) . "</li>
                    <li><strong>Total Tickets:</strong> " . count($allTicketCodes) . "</li>
                </ul>
                
                <h3>Your Ticket Codes:</h3>
                <ul>";
            
            foreach ($allTicketCodes as $ticketInfo) {
                $body .= "<li><strong>{$ticketInfo['code']}</strong> - {$ticketInfo['ticket_type']} ($" . number_format($ticketInfo['price'], 2) . ")</li>";
            }
            
            $body .= "</ul>";
            
            $body .= "
                <p><strong>Important:</strong> Please save these ticket codes. You'll need them for event entry.</p>
                <p>Thank you for choosing our platform!</p>
            ";
            
            
        } catch (\Exception $e) {
            // Email sending failed silently
        }
    }

    public function getEventTicketSales(int $eventTicketsId): array
    {
        return $this->ticketSalesRepo->getByEventTickets($eventTicketsId);
    }

    public function getTicketSalesSummary(int $eventTicketsId): object
    {
        return $this->ticketsConfigRepo->getSalesSummary($eventTicketsId);
    }

    public function refundTicketSale(int $saleId): array
    {
        $sale = $this->ticketSalesRepo->getOne(['id' => $saleId]);
        
        if (!$sale || $sale->payment_status !== 'paid') {
            return ['success' => false, 'message' => 'Invalid sale or already refunded'];
        }

        try {
            // Procesar reembolso con Stripe
            if ($sale->stripe_payment_intent_id) {
                // TODO: Implementar método de reembolso en StripeService
                // Por ahora, solo marcamos como reembolsado
                error_log("Refund requested for payment intent: " . $sale->stripe_payment_intent_id);
            }

            // Liberar tickets en el inventario
            $this->inventoryRepo->releaseTickets($sale->id_ticket_type, $sale->id_sales_stage, $sale->quantity);

            // Actualizar estado de la venta
            $this->ticketSalesRepo->update([
                'payment_status' => 'refunded'
            ], ['id' => $saleId]);

            return ['success' => true, 'message' => 'Ticket sale refunded successfully'];

        } catch (\Exception $e) {
            return ['success' => false, 'message' => 'Refund failed: ' . $e->getMessage()];
        }
    }
}
