<?php

use App\Repositories\OrderPaymentRefunds;
use App\Services\LoginService;
use App\Repositories\OrdersRepository;
use App\Repositories\OrdersPaymentsRepository;
use App\Repositories\SquareAccountsRepository;
use App\Services\SquareServiceV2;
use App\Services\AuthorizedManualChargeService;
use App\Utils\LocationUtils;
use App\Utils\TemplateResponse;
use App\Utils\MessageUtil;
use App\Utils\Router;
use App\Repositories\Connection;

$router = new Router();

$router->get(function () {
    $user = LoginService::getSession();
    $paymentRepo = new OrdersPaymentsRepository();
    $squareRepo = new SquareAccountsRepository();
    $orderRepo = new OrdersRepository();
    $manualCharge = null;

    $id_order = $_GET['id'] ?? null;

    // Filtrar por orden si aplica
    $payments = $id_order ? $paymentRepo->getAllByOrder($id_order) : $paymentRepo->getAllByOwner($user->getOwner());

    $advances = [];
    $subAdvances = [];
    if ($id_order) {
        try {
            $db = new Connection();
            $db->query("SELECT id, id_order, is_suborder, id_suborder, amount, total_before, total_after, created_at, stripe_charge_id, COALESCE(refunded_amount, 0) as refunded_amount FROM orders_advances WHERE id_order = :id AND is_suborder = 0 ORDER BY id DESC");
            $db->bind(":id", (int)$id_order);
            $db->execute();
            $advances = $db->fetchAll();

            $db->query("SELECT oa.id, s.id_order, oa.is_suborder, oa.id_suborder, oa.amount, oa.total_before, oa.total_after, oa.created_at, oa.stripe_charge_id, COALESCE(oa.refunded_amount, 0) as refunded_amount FROM orders_advances oa INNER JOIN orders_suborder s ON s.id = oa.id_suborder WHERE oa.is_suborder = 1 AND s.id_order = :id ORDER BY oa.id DESC");
            $db->bind(":id", (int)$id_order);
            $db->execute();
            $subAdvances = $db->fetchAll();
        } catch (\Throwable $e) {
            error_log("Error fetching advances: " . $e->getMessage());
            $advances = [];
            $subAdvances = [];
        }
    }
    $account = $squareRepo->getByUser($user->getId());
    if ($id_order) {
        $order = $orderRepo->getByIdWithoutOwnershipCheck((int)$id_order);
        if ($order) {
            $summary = (new AuthorizedManualChargeService())->getOrderAuthorizationSummary((object)$order);
            $reason = 'No active saved payment method and authorization are available for this order.';
            if (($summary['balance'] ?? 0) <= 0.01) {
                $reason = 'This order has no pending balance.';
            } elseif (empty($summary['method'])) {
                $reason = 'The client has no saved payment method for the active provider.';
            } elseif (empty($summary['consent'])) {
                $reason = 'The client has not authorized future/manual charges for this saved method.';
            } elseif (empty($summary['provider_supports_saved_charges'])) {
                $reason = 'The active payment provider cannot charge saved payment methods.';
            }

            $manualCharge = [
                'eligible' => (bool)($summary['can_charge'] ?? false),
                'balance' => (float)($summary['balance'] ?? 0),
                'methods' => !empty($summary['method']) ? [$summary['method']] : [],
                'reason' => $reason,
            ];
        }
    }

    // Unificar pagos + abonos en una sola lista para mostrar "como pagos"
    $paymentsAll = [];
    if ($id_order) {
        foreach ($payments as $p) {
            $p->entry_type = 'payment';
            $paymentsAll[] = $p;
        }
        foreach ($advances as $a) {
            $row = (object)[
                'id_order' => $a->id_order,
                'amount' => (float)$a->amount,
                'refunded_amount' => (float)($a->refunded_amount ?? 0),
                'method' => $a->stripe_charge_id ? 'square' : 'manual',
                'stripe_charge_id' => $a->stripe_charge_id,
                'paid_at' => $a->created_at,
                'entry_type' => 'advance',
            ];
            $paymentsAll[] = $row;
        }
        foreach ($subAdvances as $a) {
            $row = (object)[
                'id_order' => $a->id_order,
                'amount' => (float)$a->amount,
                'refunded_amount' => (float)($a->refunded_amount ?? 0),
                'method' => $a->stripe_charge_id ? 'square' : 'manual',
                'stripe_charge_id' => $a->stripe_charge_id,
                'paid_at' => $a->created_at,
                'entry_type' => 'advance',
            ];
            $paymentsAll[] = $row;
        }
        usort($paymentsAll, function($x, $y){
            return strtotime($y->paid_at ?? '1970-01-01') <=> strtotime($x->paid_at ?? '1970-01-01');
        });
    } else {
        // listado por owner: dejamos solo pagos tradicionales
        $paymentsAll = $payments;
        foreach ($paymentsAll as $p) { $p->entry_type = 'payment'; }
    }

    // Verificar estado en Square si hay cuenta conectada
    // Square no requiere verificación adicional en tiempo real como Stripe
    // El estado se actualiza durante el proceso de onboarding

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "payments" => $paymentsAll,
        "account" => $account,
        "id_order" => $id_order,
        "advances" => $advances,
        "manualCharge" => $manualCharge
    ]);
});

$router->post(function () {
    $paymentRepo = new OrdersPaymentsRepository();
    $squareService = new SquareServiceV2();
    $squareRepo = new SquareAccountsRepository();
    $refundRepo = new OrderPaymentRefunds();
    $action = $_POST['action'] ?? 'refund';
    if ($action === 'authorized_manual_charge') {
        $id_order = (int)($_GET['id'] ?? 0);
        $amount = (float)($_POST['manual_charge_amount'] ?? 0);
        $session = LoginService::getSession();
        $result = (new AuthorizedManualChargeService())->chargeOrderBalance(
            $id_order,
            (int)$session->getId(),
            (int)$session->getLevel(),
            $amount > 0 ? $amount : null
        );
        MessageUtil::setMessage(
            $result['message'] ?? ($result['success'] ? 'Charge processed.' : 'Charge failed.'),
            $result['success'] ? 'Success' : 'Error',
            $result['success'] ? 'success' : 'error'
        );
        LocationUtils::reload();
    }

    $account = $squareRepo->getByUser(LoginService::getSession()->getId());

    $id_order = $_GET['id'] ?? null;
    $chargeId = $_POST["charge_id"] ?? null;
    $refundAmount = floatval($_POST["refund_amount"] ?? 0);

    if (!$account || !$account->square_account_id) {
        MessageUtil::setMessage("❌ Square account not found.");
        LocationUtils::reload();
    }

    $payment = $paymentRepo->getOne([
        "id_order" => $id_order,
        "stripe_charge_id" => $chargeId
    ]);

    $isAdvance = false;
    $advanceId = null;

    if (!$payment) {
        $db = new Connection();
        $db->query("SELECT * FROM orders_advances WHERE stripe_charge_id = :charge_id AND id_order = :order_id LIMIT 1");
        $db->bind(":charge_id", $chargeId);
        $db->bind(":order_id", (int)$id_order);
        $db->execute();
        $advance = $db->fetchAll()[0] ?? null;

        if ($advance) {
            $isAdvance = true;
            $advanceId = $advance->id;
            $payment = (object)[
                'id' => $advance->id,
                'amount' => (float)$advance->amount,
                'refunded_amount' => (float)($advance->refunded_amount ?? 0),
                'stripe_charge_id' => $advance->stripe_charge_id
            ];
        }
    }

    if (!$payment) {
        MessageUtil::setMessage("❌ Payment not found.");
        LocationUtils::reload();
    }

    if ($refundAmount == 0) {
        $refundAmount = $payment->amount - ($payment->refunded_amount ?? 0);
    }

    $refund = $squareService->refundChargeOnConnectedAccount($chargeId, $account->square_account_id, $refundAmount);

    if (!$refund) {
        MessageUtil::setMessage("❌ Error refunding charge.");
        LocationUtils::reload();
    }

    if (!$isAdvance) {
        $refundRepo->add([
            'payment_id' => $payment->id,
            'refund_id' => $refund->id,
            'refund_amount' => $refundAmount,
        ]);

        $total_refunded = ($payment->refunded_amount ?? 0) + $refundAmount;
        $paymentRepo->markRefunded($chargeId, $total_refunded);
    } else {
        $total_refunded = ($payment->refunded_amount ?? 0) + $refundAmount;
        $db = new Connection();
        $db->query("UPDATE orders_advances SET refunded_amount = :refunded WHERE id = :id");
        $db->bind(":refunded", $total_refunded);
        $db->bind(":id", $advanceId);
        $db->execute();
    }

    if ($refundAmount == $payment->amount) {
        MessageUtil::setMessage("✅ Full refund processed.");
    } else if ($total_refunded == $payment->amount){
        MessageUtil::setMessage("✅ Full refund processed.");
    } else {
        MessageUtil::setMessage("✅ Partial refund processed.");
    }

    LocationUtils::reload();
});

$router->run();
