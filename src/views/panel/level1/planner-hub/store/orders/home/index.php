<?php

use App\Repositories\StoreOrdersRepository;
use App\Repositories\StoreOrderItemsRepository;
use App\Repositories\StoreOrderWorkflowRepository;
use App\Repositories\StoreUserRolesRepository;
use App\Repositories\Connection;
use App\Services\EmailServiceFactory;
use App\Services\LoginService;
use App\Utils\LocationUtils;
use App\Utils\Router;
use App\Utils\TemplateResponse;
use App\Utils\MessageUtil;
use App\Utils\AvomealContext;

$router = new Router();

$router->get(function () {
    $ordersRepo = new StoreOrdersRepository();
    $itemsRepo = new StoreOrderItemsRepository();
    $workflowRepo = new StoreOrderWorkflowRepository();
    $storeRolesRepo = new StoreUserRolesRepository();

    $ownerId = AvomealContext::ownerId();

    $weekStartInput = trim($_GET['week_start'] ?? '');
    $weekEndInput = trim($_GET['week_end'] ?? '');
    $paymentStatus = trim($_GET['payment_status'] ?? '');
    $status = trim($_GET['status'] ?? '');
    $email = trim($_GET['email'] ?? '');

    $today = new DateTimeImmutable('now');
    $weekStart = $weekStartInput !== ''
        ? DateTimeImmutable::createFromFormat('Y-m-d', $weekStartInput)
        : $today->modify('monday this week');
    $weekEnd = $weekEndInput !== ''
        ? DateTimeImmutable::createFromFormat('Y-m-d', $weekEndInput)
        : $today->modify('sunday this week');

    if (!$weekStart) {
        $weekStart = $today->modify('monday this week');
    }
    if (!$weekEnd) {
        $weekEnd = $today->modify('sunday this week');
    }

    $orders = $ordersRepo->getByOwnerAndDateRange(
        $ownerId,
        $weekStart->setTime(0, 0, 0)->format('Y-m-d H:i:s'),
        $weekEnd->setTime(23, 59, 59)->format('Y-m-d H:i:s'),
        300
    );

    if ($paymentStatus !== '') {
        $orders = array_values(array_filter($orders, function ($o) use ($paymentStatus) {
            return strtoupper((string)$o->payment_status) === strtoupper($paymentStatus);
        }));
    }
    if ($status !== '') {
        $orders = array_values(array_filter($orders, function ($o) use ($status) {
            return strtoupper((string)$o->status) === strtoupper($status);
        }));
    }
    if ($email !== '') {
        $orders = array_values(array_filter($orders, function ($o) use ($email) {
            return stripos((string)($o->guest_email ?? ''), $email) !== false;
        }));
    }

    $deliveryUsers = $storeRolesRepo->getUsersByOwnerAndRole($ownerId, 'delivery');
    $kitchenUsers = $storeRolesRepo->getUsersByOwnerAndRole($ownerId, 'kitchen');
    $orderIds = array_map(fn($o) => (int)$o->id, $orders);
    $workflowMap = $workflowRepo->getMapByOrders($orderIds);
    $deliveryUsersById = [];
    $kitchenUsersById = [];
    foreach ($deliveryUsers as $u) {
        $deliveryUsersById[(int)$u->id_user] = trim(($u->name ?? '') . ' ' . ($u->lastname ?? ''));
    }
    foreach ($kitchenUsers as $u) {
        $kitchenUsersById[(int)$u->id_user] = trim(($u->name ?? '') . ' ' . ($u->lastname ?? ''));
    }

    foreach ($orders as &$order) {
        $shippingParts = array_filter([
            trim((string)($order->shipping_address_1 ?? '')),
            trim((string)($order->shipping_city ?? '')),
            trim((string)(
                trim((string)($order->shipping_state ?? '')) .
                (((string)($order->shipping_zip ?? '') !== '') ? (' ' . trim((string)$order->shipping_zip)) : '')
            ))
        ], function ($v) {
            return $v !== '';
        });
        $order->shipping_address_display = $shippingParts
            ? implode(', ', $shippingParts)
            : ((string)($order->city ?? '') !== '' ? (string)$order->city : '—');

        $items = $itemsRepo->getByOrder((int)$order->id);
        $order->items_summary = [];
        $order->items_meals_total = 0;
        $modalItems = [];

        foreach ($items as $idx => $item) {
            $order->items_meals_total += (int)($item->quantity ?? 0);
            if ($idx < 3) {
                $order->items_summary[] = sprintf(
                    '%s × %d',
                    $item->product_name_snapshot ?? ('#' . $item->id_product),
                    (int)($item->quantity ?? 0)
                );
            }

            $configuration = json_decode((string)($item->configuration_snapshot ?? ''), true);
            $configuration = is_array($configuration) ? $configuration : [];
            $detailParts = [];
            if (!empty($item->servings)) $detailParts[] = (int)$item->servings . ' servings';
            foreach ($configuration as $label => $value) {
                if (is_scalar($value) && trim((string)$value) !== '') $detailParts[] = ucwords(str_replace('_', ' ', (string)$label)) . ': ' . (string)$value;
            }
            $modalItems[] = [
                'name' => ($item->product_name_snapshot ?? ('#' . $item->id_product)) . ($detailParts ? ' — ' . implode(' · ', $detailParts) : ''),
                'quantity' => (int)($item->quantity ?? 0),
                'unit_price' => (float)($item->unit_price ?? 0),
                'line_total' => (float)($item->line_total ?? 0),
            ];
        }
        if (count($items) > 3) {
            $order->items_summary[] = '+' . (count($items) - 3) . ' more';
        }

        $order->items_modal = $modalItems;
        $order->items_modal_json = json_encode($modalItems);
        $wf = $workflowMap[(int)$order->id] ?? null;
        $order->status_label = StoreOrdersRepository::statusLabel($order->status ?? '');
        $order->status_badge_class = StoreOrdersRepository::statusBadgeClass($order->status ?? '');
        $order->kitchen_user_id = $wf ? (int)($wf->kitchen_user_id ?? 0) : 0;
        $order->delivery_user_id = $wf ? (int)($wf->delivery_user_id ?? 0) : 0;
        $order->kitchen_assignee_name = $order->kitchen_user_id > 0
            ? ($kitchenUsersById[$order->kitchen_user_id] ?? '')
            : '';
        $order->delivery_assignee_name = $order->delivery_user_id > 0
            ? ($deliveryUsersById[$order->delivery_user_id] ?? '')
            : '';
    }
    unset($order);

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "orders" => $orders,
        "deliveryUsers" => $deliveryUsers,
        "kitchenUsers" => $kitchenUsers,
        "orderStatusOptions" => StoreOrdersRepository::statusOptions(),
        "filters" => [
            "week_start" => $weekStart->format('Y-m-d'),
            "week_end" => $weekEnd->format('Y-m-d'),
            "payment_status" => $paymentStatus,
            "status" => $status,
            "email" => $email
        ]
    ]);
});

$router->post(function () {
    $ordersRepo = new StoreOrdersRepository();
    $workflowRepo = new StoreOrderWorkflowRepository();
    $ownerId = AvomealContext::ownerId();

    $action = $_POST['action'] ?? '';
    $orderId = isset($_POST['order_id']) ? (int)$_POST['order_id'] : 0;

    if ($orderId <= 0) {
        MessageUtil::setMessage('Invalid order id.');
        LocationUtils::reload();
    }

    $order = $ordersRepo->getById($orderId);
    if (!$order || (int)($order->id_owner ?? 0) !== $ownerId) {
        MessageUtil::setMessage('Order not found for Avomeal.');
        LocationUtils::reload();
    }

    if ($action === 'update_status') {
        $newStatus = trim($_POST['status'] ?? '');
        if ($newStatus === '' || !array_key_exists($newStatus, StoreOrdersRepository::statusOptions())) {
            MessageUtil::setMessage('Select a valid status.');
            LocationUtils::reload();
        }

        $ok = $ordersRepo->updateStatus($orderId, $newStatus);
        if ($ok && in_array($newStatus, [
            StoreOrdersRepository::STATUS_READY,
            StoreOrdersRepository::STATUS_READY_FOR_DELIVERY,
        ], true)) {
            $workflowRepo->markKitchenReady($orderId);
        }
        if ($ok && $newStatus === StoreOrdersRepository::STATUS_OUT_FOR_DELIVERY) {
            $workflowRepo->markSending($orderId);
        }
        if ($ok && in_array($newStatus, [
            StoreOrdersRepository::STATUS_DELIVERED,
            StoreOrdersRepository::STATUS_COMPLETED,
        ], true)) {
            $workflowRepo->markDelivered($orderId, null, null);
        }
        MessageUtil::setMessage($ok ? 'Order status updated.' : 'Failed to update order status.');
        LocationUtils::reload();
    }

    if ($action === 'update_payment_status') {
        $newPayment = trim($_POST['payment_status'] ?? '');
        $ok = false;
        if ($newPayment === StoreOrdersRepository::PAYMENT_PAID) {
            $ok = $ordersRepo->markAsPaid($orderId);
        } elseif ($newPayment === StoreOrdersRepository::PAYMENT_FAILED) {
            $ok = $ordersRepo->markAsFailed($orderId);
        } elseif ($newPayment === StoreOrdersRepository::PAYMENT_REFUNDED) {
            $ok = $ordersRepo->markAsRefunded($orderId);
        }
        MessageUtil::setMessage($ok ? 'Payment status updated.' : 'Failed to update payment status.');
        LocationUtils::reload();
    }

    if ($action === 'assign_delivery') {
        $kitchenUserId = isset($_POST['kitchen_user_id']) && $_POST['kitchen_user_id'] !== ''
            ? (int)$_POST['kitchen_user_id']
            : null;
        $deliveryUserId = isset($_POST['delivery_user_id']) && $_POST['delivery_user_id'] !== ''
            ? (int)$_POST['delivery_user_id']
            : null;
        $ok = $workflowRepo->upsertAssignments($ownerId, $orderId, $kitchenUserId, $deliveryUserId);
        MessageUtil::setMessage($ok ? 'Order assignments updated.' : 'Failed to update order assignments.');
        LocationUtils::reload();
    }

    if ($action === 'override_delivery_fee') {
        $newFee=max(0,round((float)($_POST['override_fee']??0),2));$reason=trim((string)($_POST['override_reason']??''));
        if($reason===''||mb_strlen($reason)<5){MessageUtil::setMessage('Enter a clear reason for the delivery fee override.');LocationUtils::reload();}
        $original=(float)($order->delivery_fee??0);$paid=strtoupper((string)$order->payment_status)==='PAID';$user=LoginService::getSession();$db=new Connection();
        $db->query("INSERT INTO store_delivery_fee_overrides (id_owner,site_key,id_store_order,original_fee,override_fee,reason,payment_state,created_by) VALUES (:owner,'vnvevents',:order,:original,:override,:reason,:state,:user)");foreach([':owner'=>$ownerId,':order'=>$orderId,':original'=>$original,':override'=>$newFee,':reason'=>$reason,':state'=>$paid?'AFTER_PAYMENT':'BEFORE_PAYMENT',':user'=>(int)$user->getId()] as $key=>$value)$db->bind($key,$value);$db->execute();
        if(!$paid){$settings=(new \App\Services\Delivery\DeliveryPricingService())->settings($ownerId,'vnvevents');$tax=round((max(0,(float)$order->subtotal-(float)$order->discount)+$newFee)*max(0,(float)($settings['tax_rate_percent']??0))/100,2);$total=round(max(0,(float)$order->subtotal-(float)$order->discount)+$newFee+$tax,2);$ordersRepo->update(['delivery_fee'=>$newFee,'delivery_margin'=>round($newFee-(float)$order->delivery_provider_cost,2),'delivery_pricing_source'=>'ADMIN_OVERRIDE','tax_total'=>$tax,'total'=>$total],['id'=>$orderId,'id_owner'=>$ownerId]);}
        MessageUtil::setMessage($paid?'Override recorded for internal review; the paid transaction was not changed.':'Delivery fee override applied.');LocationUtils::reload();
    }

    if ($action === 'update_delivery_details') {
        $address=['address_1'=>trim((string)($_POST['shipping_address_1']??'')),'address_2'=>trim((string)($_POST['shipping_address_2']??'')),'city'=>trim((string)($_POST['shipping_city']??'')),'state'=>trim((string)($_POST['shipping_state']??'')),'zip'=>trim((string)($_POST['shipping_zip']??'')),'country'=>'US'];$requested=trim((string)($_POST['requested_delivery_at']??''));
        try{$quote=(new \App\Services\Delivery\DeliveryPricingService())->quoteDelivery($ownerId,'vnvevents',$address,$requested?:null,'ADMIN_ORDER',$orderId);}catch(Throwable $e){MessageUtil::setMessage($e->getMessage());LocationUtils::reload();}
        $deliveryStatus=strtoupper(trim((string)($_POST['delivery_status']??'SCHEDULED')));$allowedStatuses=['SCHEDULED','PREPARING','READY_FOR_DELIVERY','ASSIGNED','OUT_FOR_DELIVERY','DELIVERED','CANCELLED'];if(!in_array($deliveryStatus,$allowedStatuses,true))$deliveryStatus='SCHEDULED';
        $paid=strtoupper((string)$order->payment_status)==='PAID';$update=['shipping_address_1'=>$address['address_1'],'shipping_address_2'=>$address['address_2'],'shipping_city'=>$address['city'],'shipping_state'=>$address['state'],'shipping_zip'=>$address['zip'],'shipping_country'=>'US','shipping_instructions'=>trim((string)($_POST['shipping_instructions']??''))?:null,'requested_delivery_at'=>$requested?:null,'promised_delivery_at'=>$requested?:null,'delivery_operational_provider'=>trim((string)($_POST['delivery_operational_provider']??''))?:null,'delivery_status'=>$deliveryStatus,'delivery_provider_cost'=>(float)$quote['provider_cost'],'delivery_quote_id'=>(int)$quote['quote_id'],'delivery_pricing_source'=>(string)$quote['pricing_source'],'delivery_distance_miles'=>(float)$quote['distance_miles'],'delivery_markup_percent'=>(float)$quote['markup_percent'],'delivery_reference_quoted_at'=>(string)$quote['queried_at']];
        if(!$paid){$settings=(new \App\Services\Delivery\DeliveryPricingService())->settings($ownerId,'vnvevents');$update['delivery_fee']=(float)$quote['customer_fee'];$update['delivery_margin']=(float)$quote['delivery_margin'];$update['tax_total']=round((max(0,(float)$order->subtotal-(float)$order->discount)+$update['delivery_fee'])*max(0,(float)($settings['tax_rate_percent']??0))/100,2);$update['total']=round(max(0,(float)$order->subtotal-(float)$order->discount)+$update['delivery_fee']+$update['tax_total'],2);}
        $ordersRepo->update($update,['id'=>$orderId,'id_owner'=>$ownerId]);MessageUtil::setMessage($paid?'Delivery details updated; the customer charge remained locked.':'Delivery details and payable total recalculated.');LocationUtils::reload();
    }

    if ($action === 'record_delivery_refund') {
        $amount=max(0,round((float)($_POST['refund_amount']??0),2));$remaining=max(0,(float)$order->delivery_fee-(float)($order->delivery_refunded_amount??0));$method=strtoupper(trim((string)($_POST['refund_method']??'OTHER')));$allowed=['ZELLE','CASH','CHECK','MANUAL_SQUARE','MANUAL_STRIPE','OTHER'];if(!in_array($method,$allowed,true))$method='OTHER';
        if($amount<=0||$amount>$remaining){MessageUtil::setMessage('Refund amount must be greater than zero and cannot exceed the remaining delivery fee.');LocationUtils::reload();}
        $proofUrl=null;if(!empty($_FILES['refund_proof']['tmp_name'])){$mime=(new finfo(FILEINFO_MIME_TYPE))->file($_FILES['refund_proof']['tmp_name']);$ext=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','application/pdf'=>'pdf'][$mime]??null;if(!$ext){MessageUtil::setMessage('Refund proof must be JPG, PNG, WebP or PDF.');LocationUtils::reload();}$dir=dirname(__DIR__,8).'/public/uploads/delivery-refunds/'.$ownerId;if(!is_dir($dir))mkdir($dir,0770,true);$name='order-'.$orderId.'-'.bin2hex(random_bytes(8)).'.'.$ext;if(!move_uploaded_file($_FILES['refund_proof']['tmp_name'],$dir.'/'.$name)){MessageUtil::setMessage('Refund proof could not be stored.');LocationUtils::reload();}$proofUrl='/uploads/delivery-refunds/'.$ownerId.'/'.$name;}
        $db=new Connection();$user=LoginService::getSession();$db->query("INSERT INTO store_delivery_refunds (id_owner,site_key,id_store_order,amount,method,status,refund_date,reference,internal_note,proof_url,created_by) VALUES (:owner,'vnvevents',:order,:amount,:method,'ISSUED',:date,:reference,:note,:proof,:user)");foreach([':owner'=>$ownerId,':order'=>$orderId,':amount'=>$amount,':method'=>$method,':date'=>trim((string)($_POST['refund_date']??date('Y-m-d'))),':reference'=>trim((string)($_POST['refund_reference']??''))?:null,':note'=>trim((string)($_POST['refund_note']??''))?:null,':proof'=>$proofUrl,':user'=>(int)$user->getId()] as $key=>$value)$db->bind($key,$value);$db->execute();
        $refunded=round((float)($order->delivery_refunded_amount??0)+$amount,2);$status=$refunded+0.001>=(float)$order->delivery_fee?'REFUNDED':'PARTIAL';$ordersRepo->update(['delivery_refunded_amount'=>$refunded,'delivery_refund_status'=>$status],['id'=>$orderId,'id_owner'=>$ownerId]);
        if(!empty($order->guest_email)){try{EmailServiceFactory::sendWithOwnerProvider($ownerId,(string)$order->guest_email,'Your VNV delivery refund was issued','<p>Your delivery has been cancelled or adjusted.</p><p>A refund of <strong>$'.number_format($amount,2).'</strong> has been issued on '.htmlspecialchars(trim((string)($_POST['refund_date']??date('Y-m-d')))).'.</p><p>Contact us if you need assistance.</p>',true);}catch(Throwable $e){error_log('[Delivery refund email] '.$e->getMessage());}}
        MessageUtil::setMessage('Manual delivery refund recorded.');LocationUtils::reload();
    }

    MessageUtil::setMessage('Invalid action.');
    LocationUtils::reload();
});

$router->run();
