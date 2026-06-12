<?php

namespace App\Services;

use App\Repositories\AuthorizedManualChargeLogsRepository;
use App\Repositories\ClientAutoChargeConsentsRepository;
use App\Repositories\Connection;
use App\Repositories\OrdersPaymentsRepository;
use App\Repositories\OrdersRepository;
use App\Repositories\OrdersStatusHistoryRepository;
use App\Repositories\PaymentProvidersRepository;
use App\Repositories\UserRepository;
use App\Services\Payment\PaymentProviderFactory;

class AuthorizedManualChargeService
{
    private OrdersRepository $orders;
    private OrdersPaymentsRepository $payments;
    private PaymentProvidersRepository $providers;
    private ClientPaymentMethodService $methods;
    private ClientAutoChargeConsentsRepository $consents;
    private AuthorizedManualChargeLogsRepository $logs;

    public function __construct()
    {
        $this->orders = new OrdersRepository();
        $this->payments = new OrdersPaymentsRepository();
        $this->providers = new PaymentProvidersRepository();
        $this->methods = new ClientPaymentMethodService();
        $this->consents = new ClientAutoChargeConsentsRepository();
        $this->logs = new AuthorizedManualChargeLogsRepository();
    }

    public function getOrderAuthorizationSummary(object $order): array
    {
        $businessId = $this->providers->getPaymentOwnerIdForOrder($order);
        $activeProvider = $this->providers->getActiveProviderForOwner($businessId);
        $providerType = strtolower((string)($activeProvider->provider_type ?? ''));
        $clientId = (int)($order->id_client ?? 0);
        $balance = $this->calculateMainOrderBalance((int)$order->id);
        $method = null;
        $consent = null;

        if ($businessId > 0 && $clientId > 0 && $providerType !== '') {
            foreach ($this->methods->listClientSavedPaymentMethodsForProvider($businessId, $clientId, $providerType) as $candidate) {
                $candidateConsent = $this->methods->getActiveConsentForMethod($businessId, $clientId, (int)$candidate->id);
                if ($candidateConsent) {
                    $method = $candidate;
                    $consent = $candidateConsent;
                    break;
                }
            }
        }

        return [
            'business_id' => $businessId,
            'provider_type' => $providerType,
            'provider_supports_saved_charges' => $activeProvider ? PaymentProviderFactory::create($activeProvider)->supportsChargingSavedPaymentMethods() : false,
            'balance' => $balance,
            'method' => $method,
            'consent' => $consent,
            'can_charge' => $activeProvider
                && $method
                && $consent
                && $balance > 0.01
                && PaymentProviderFactory::create($activeProvider)->supportsChargingSavedPaymentMethods(),
        ];
    }

    public function chargeOrderBalance(int $orderId, int $adminUserId, int $adminLevel, ?float $amount = null): array
    {
        if ($adminLevel !== 1) {
            return ['success' => false, 'message' => 'Only Level 1 administrators can charge authorized balances in VNV Events.'];
        }

        $order = $this->orders->getByIdWithoutOwnershipCheck($orderId);
        if (!$order) {
            return ['success' => false, 'message' => 'Order not found.'];
        }
        $order = (object)$order;

        $summary = $this->getOrderAuthorizationSummary($order);
        $chargeAmount = $amount !== null ? round($amount, 2) : round((float)$summary['balance'], 2);
        if ($chargeAmount <= 0 || $chargeAmount - (float)$summary['balance'] > 0.01) {
            return ['success' => false, 'message' => 'Invalid charge amount or amount exceeds pending balance.'];
        }

        if (!$summary['can_charge']) {
            $this->writeLog($summary, $order, $chargeAmount, $adminUserId, 'FAILED', null, 'No active saved payment method and consent for this provider.');
            return ['success' => false, 'message' => 'No active saved payment method and authorization are available for this order.'];
        }

        $idempotencyKey = 'order:' . $orderId . ':main:' . number_format($chargeAmount, 2, '.', '') . ':provider:' . $summary['provider_type'];
        if ($this->logs->findByIdempotencyKey($idempotencyKey)) {
            return ['success' => false, 'message' => 'This authorized charge was already completed.'];
        }

        $activeProvider = $this->providers->getActiveProviderForOwner((int)$summary['business_id']);
        $provider = PaymentProviderFactory::create($activeProvider);
        $charge = $provider->chargeSavedPaymentMethod($summary['method'], $chargeAmount, [
            'description' => 'Authorized balance charge for VNV order #' . $orderId,
            'reference_id' => 'VNV-341' . $orderId,
            'order_id' => $orderId,
            'source' => 'admin_authorized_manual_charge',
        ]);

        if ($charge === false || (empty($charge->paid) && !in_array((string)($charge->status ?? ''), ['completed', 'succeeded', 'COMPLETED'], true))) {
            $this->writeLog($summary, $order, $chargeAmount, $adminUserId, 'FAILED', $charge->id ?? null, 'Gateway charge failed.', $idempotencyKey);
            return ['success' => false, 'message' => 'The gateway could not process the authorized charge.'];
        }

        $this->payments->add([
            'id_order' => $orderId,
            'id_suborder' => null,
            'is_suborder' => 0,
            'amount' => $chargeAmount,
            'method' => $summary['provider_type'],
            'stripe_charge_id' => $charge->id ?? null,
            'paid_at' => date('Y-m-d H:i:s'),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $remaining = max($this->calculateMainOrderBalance($orderId), 0);
        $newStatus = $remaining <= 0.01 ? 'INVOICE_PAID' : 'INVOICE_PARTIAL';
        $this->orders->update(['status_workflow' => $newStatus], ['id' => $orderId]);
        (new OrdersStatusHistoryRepository())->add([
            'id_order' => $orderId,
            'status' => $newStatus,
            'action_type' => 'authorized_manual_charge',
            'note' => 'Authorized saved payment method charged for $' . number_format($chargeAmount, 2),
            'created_by' => $adminUserId,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $this->writeLog($summary, $order, $chargeAmount, $adminUserId, 'SUCCESS', $charge->id ?? null, null, $idempotencyKey);
        $this->sendSuccessEmail($order, $chargeAmount, $summary['provider_type']);

        return ['success' => true, 'message' => 'Authorized balance charged successfully.'];
    }

    private function calculateMainOrderBalance(int $orderId): float
    {
        $total = (float)$this->orders->calculateTotal($orderId);
        $paid = 0.0;
        foreach ($this->payments->getMainByOrder($orderId) as $payment) {
            $paid += max(0.0, (float)($payment->amount ?? 0) - (float)($payment->refunded_amount ?? 0));
        }

        $advances = 0.0;
        try {
            $db = new Connection();
            $db->query("SELECT COALESCE(SUM(amount),0) AS total_advanced FROM orders_advances WHERE id_order = :id AND (id_suborder IS NULL OR id_suborder = 0)");
            $db->bind(':id', $orderId);
            $row = $db->fetchOne();
            $advances = (float)($row->total_advanced ?? 0);
        } catch (\Throwable $e) {
            $advances = 0.0;
        }

        return round(max($total - $paid - $advances, 0), 2);
    }

    private function writeLog(array $summary, object $order, float $amount, int $adminUserId, string $status, ?string $chargeId, ?string $error = null, ?string $idempotencyKey = null): void
    {
        $this->logs->add([
            'id_user_business' => (int)($summary['business_id'] ?? 0),
            'id_client' => (int)($order->id_client ?? 0),
            'id_order' => (int)$order->id,
            'id_suborder' => null,
            'saved_payment_method_id' => isset($summary['method']->id) ? (int)$summary['method']->id : null,
            'auto_charge_consent_id' => isset($summary['consent']->id) ? (int)$summary['consent']->id : null,
            'payment_provider' => (string)($summary['provider_type'] ?? ''),
            'amount' => $amount,
            'currency' => strtoupper((string)($order->currency ?? 'USD')),
            'status' => $status,
            'gateway_charge_id' => $chargeId,
            'error_message' => $error,
            'charged_by_user_id' => $adminUserId,
            'idempotency_key' => $idempotencyKey,
            'metadata_json' => json_encode(['source' => 'admin_level_1_order_status']),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function sendSuccessEmail(object $order, float $amount, string $provider): void
    {
        try {
            $client = (new UserRepository())->getOneWithoutOwnership(['id' => (int)$order->id_client]);
            if (!$client || empty($client->email)) {
                return;
            }

            $body = '<p>Hello,</p>'
                . '<p>A pending balance payment of <strong>$' . number_format($amount, 2) . '</strong> was processed for order <strong>VNV-341' . (int)$order->id . '</strong> using your authorized saved payment method.</p>'
                . '<p>Provider: ' . htmlspecialchars(ucfirst($provider), ENT_QUOTES, 'UTF-8') . '</p>';
            (new EmailService())->sendSimpleEmail((string)$client->email, 'Your authorized payment was processed', $body, true);
        } catch (\Throwable $e) {
            error_log('Authorized payment email failed: ' . $e->getMessage());
        }
    }
}
