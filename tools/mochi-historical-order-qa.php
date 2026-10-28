<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$root = dirname(__DIR__);
require_once $root.'/vendor/autoload.php';
Dotenv\Dotenv::createImmutable($root)->safeLoad();

use App\Repositories\Connection;
use App\Services\MochiActionService;

$template = (string)file_get_contents($root.'/src/views/panel/level1/planner-hub/management/orders/orders/edit/index.twig');
$historyTemplate = (string)file_get_contents($root.'/src/views/panel/level1/planner-hub/management/orders/orders/previous/index.twig');
if (str_contains($template, 'min="{{ "now"|date("Y-m-d") }}"')) {
    throw new RuntimeException('The Level 1 edit form still blocks historical event dates.');
}
if (!str_contains($template, 'name="payment_split_type" class="form-select" id="paymentSplitSelect">')) {
    throw new RuntimeException('The payment schedule is still disabled for restricted administrative edits.');
}
if (!str_contains($historyTemplate, "orders/orders/edit') }}?id={{ order.id }}") || !str_contains($historyTemplate, 'Edit Order')) {
    throw new RuntimeException('The historical orders list does not expose the exact-order edit action.');
}

$db = new Connection();
$db->query("SELECT id,id_client,address,payment_split_type,payment_split_percent_1,payment_split_percent_2
            FROM orders
            WHERE id_owner=2 AND id_client IS NOT NULL AND event_date<CURDATE() AND COALESCE(is_archived,0)=0
            ORDER BY event_date DESC,id DESC LIMIT 1");
$order = $db->fetchOne();
if (!$order) throw new RuntimeException('No local historical order is available for the transactional QA.');

$payload = ['order_id'=>(int)$order->id,'user_id'=>(int)$order->id_client,'exp'=>time()+3600];
$payload['hash'] = hash_hmac('sha256', json_encode($payload), $_ENV['VNV_SECRET_KEY'] ?? 'mySuperSecretKey');
$url = 'https://vnvevents.com/order-access?token='.urlencode(base64_encode(json_encode($payload)));
$service = new MochiActionService(2, 'vnvevents', $db);
$context = $service->resolveOrderAccessContext('Corrige esta orden histórica: '.$url);
if ((int)($context['order_id'] ?? 0) !== (int)$order->id) {
    throw new RuntimeException('Mochi did not resolve the signed link to the exact historical order.');
}

$nextType = (int)$order->payment_split_type === 1 ? 2 : 1;
$perform = new ReflectionMethod(MochiActionService::class, 'perform');
$db->beginTransaction();
try {
    $perform->invoke($service, 'UPDATE_ORDER', [
        'order_id'=>(int)$order->id,
        'address'=>(string)$order->address,
        'payment_split_type'=>$nextType,
    ], 1);
    $db->query('SELECT address,payment_split_type,payment_split_percent_1,payment_split_percent_2 FROM orders WHERE id=:id');
    $db->bind(':id', (int)$order->id);
    $updated = $db->fetchOne();
    if ((string)$updated->address !== (string)$order->address || (int)$updated->payment_split_type !== $nextType) {
        throw new RuntimeException('The protected administrative update did not persist inside the QA transaction.');
    }
    if ($nextType === 1 && ((int)$updated->payment_split_percent_1 !== 100 || (int)$updated->payment_split_percent_2 !== 0)) {
        throw new RuntimeException('Single-payment normalization is incorrect.');
    }
} finally {
    $db->rollback();
}

fwrite(STDOUT, "PASS: history exposes the exact edit action, a signed link resolves an exact historical order, Level 1 can edit its past date, and Mochi can transactionally correct address/payment schedule without changing live data.\n");
