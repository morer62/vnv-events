<?php

use App\Repositories\InvoicesRepository;
use App\Services\InvoiceService;
use App\Services\LoginService;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router=new Router();
$router->get(function(){
    $session=LoginService::getSession();$owner=(int)$session->getOwner();$service=new InvoiceService();
    try{$service->reconcilePaidOrders($owner);$service->refreshStatuses($owner);}catch(Throwable $e){error_log('[Invoices reconciliation] '.$e->getMessage());}
    $filters=['client'=>trim((string)($_GET['client']??'')),'number'=>trim((string)($_GET['number']??'')),'order'=>trim((string)($_GET['order']??'')),'status'=>trim((string)($_GET['status']??'')),'from'=>trim((string)($_GET['from']??'')),'to'=>trim((string)($_GET['to']??''))];
    try{$rows=(new InvoicesRepository())->search($owner,$filters);}catch(Throwable $e){$rows=[];MessageUtil::setMessage('Invoice tables are not available yet. Apply db/20261028_invoices_module.sql.','Invoices','error');}
    return TemplateResponse::render(__DIR__.'/index.twig',['invoices'=>$rows,'filters'=>$filters]);
});
$router->post(function(){
    $session=LoginService::getSession();$orderId=(int)($_POST['order_id']??0);
    try{$invoice=(new InvoiceService())->ensureForOrder((int)$session->getOwner(),$orderId,(int)$session->getId(),false);LocationUtils::redirectInternal('panel/planner-hub/management/invoices/view?id='.(int)$invoice->id);}
    catch(Throwable $e){MessageUtil::setMessage($e->getMessage(),'Invoice not created','error');LocationUtils::reload();}
});
$router->run();
