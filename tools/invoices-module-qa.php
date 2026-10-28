<?php
declare(strict_types=1);

if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/vendor/autoload.php';
Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();

use App\Repositories\Connection;
use App\Services\InvoicePdfGenerator;
use App\Services\InvoiceService;

$db=new Connection();
$db->query('SELECT o.id,o.id_owner FROM orders o WHERE EXISTS (SELECT 1 FROM orders_services_assigned s WHERE s.id_order=o.id) ORDER BY o.id DESC LIMIT 1');
$order=$db->fetchOne();
if(!$order){fwrite(STDERR,"No local order with services is available.\n");exit(2);}
$db->query('SELECT * FROM orders WHERE id=:id');$db->bind(':id',(int)$order->id);$before=$db->fetchOne();$beforeHash=hash('sha256',json_encode($before));
$db->query('SELECT id FROM invoices WHERE id_owner=:owner AND id_order=:order_id');$db->bind(':owner',(int)$order->id_owner);$db->bind(':order_id',(int)$order->id);$preexisting=$db->fetchOne();
try{
    $service=new InvoiceService();
    $first=$service->ensureForOrder((int)$order->id_owner,(int)$order->id,null,false);
    $second=$service->ensureForOrder((int)$order->id_owner,(int)$order->id,null,false);
    if((int)$first->id!==(int)$second->id)throw new RuntimeException('Idempotency failed.');
    $snapshot=$service->snapshot((int)$order->id_owner,(int)$first->id);
    if((string)$first->invoice_number!=='INV-'.(int)$order->id)throw new RuntimeException('Invoice number is not deterministic.');
    if(!$snapshot['lines'])throw new RuntimeException('Service lines were not loaded.');
    $issued=$service->issueVersion((int)$order->id_owner,(int)$first->id,0);
    $pdf=InvoicePdfGenerator::render($issued['snapshot'],(int)$issued['version']->version_number);
    if(!str_starts_with($pdf,'%PDF-'))throw new RuntimeException('PDF rendering failed.');
    $db->query('SELECT * FROM orders WHERE id=:id');$db->bind(':id',(int)$order->id);$after=$db->fetchOne();
    if(hash('sha256',json_encode($after))!==$beforeHash)throw new RuntimeException('Invoice flow changed the order.');
    echo json_encode(['ok'=>true,'order_id'=>(int)$order->id,'invoice_id'=>(int)$first->id,'invoice_number'=>$first->invoice_number,'status'=>$issued['snapshot']['invoice']->status,'lines'=>count($snapshot['lines']),'payments'=>count($snapshot['payments']),'pdf_bytes'=>strlen($pdf),'order_unchanged'=>true],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
} finally {
    if(!$preexisting && isset($first)){$db->query('DELETE FROM invoices WHERE id=:id');$db->bind(':id',(int)$first->id);$db->execute();}
}
