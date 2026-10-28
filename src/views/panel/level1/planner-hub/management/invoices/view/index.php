<?php

use App\Repositories\Connection;
use App\Repositories\InvoicesRepository;
use App\Services\EmailServiceFactory;
use App\Services\InvoicePdfGenerator;
use App\Services\InvoiceService;
use App\Services\LoginService;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router=new Router();
$router->get(function(){
    $s=LoginService::getSession();$id=(int)($_GET['id']??0);$service=new InvoiceService();
    try{$snapshot=$service->snapshot((int)$s->getOwner(),$id);return TemplateResponse::render(__DIR__.'/index.twig',['data'=>$snapshot,'versions'=>$service->versions($id)]);}
    catch(Throwable $e){MessageUtil::setMessage($e->getMessage(),'Invoice','error');LocationUtils::redirectInternal('panel/planner-hub/management/invoices');}
});
$router->post(function(){
    $s=LoginService::getSession();$owner=(int)$s->getOwner();$actor=(int)$s->getId();$id=(int)($_GET['id']??0);$action=(string)($_POST['action']??'save');$service=new InvoiceService();
    try{
        if($action==='void'){$service->void($owner,$id,$actor,(string)($_POST['void_reason']??''));MessageUtil::setMessage('Invoice voided. The order and payments were not changed.','Invoices','success');}
        elseif($action==='email'){
            $issued=$service->issueVersion($owner,$id,$actor);$pdf=InvoicePdfGenerator::render($issued['snapshot'],(int)$issued['version']->version_number);$to=trim((string)($_POST['email_to']??''));if(!filter_var($to,FILTER_VALIDATE_EMAIL))throw new RuntimeException('Enter a valid recipient email.');
            $invoice=$issued['snapshot']['invoice'];$result=EmailServiceFactory::sendWithOwnerProviderAttachment($owner,$to,'Invoice '.$invoice->invoice_number,'<p>Please find your VNV Events invoice attached.</p>',$pdf,$invoice->invoice_number.'.pdf');if(empty($result['success']))throw new RuntimeException((string)$result['message']);
            $db=new Connection();$db->query('UPDATE invoice_versions SET sent_to=:to_email,sent_by=:actor,sent_at=NOW() WHERE id=:id');$db->bind(':to_email',$to);$db->bind(':actor',$actor);$db->bind(':id',(int)$issued['version']->id);$db->execute();(new InvoicesRepository())->audit($id,$actor,'EMAILED',['to'=>$to,'version'=>(int)$issued['version']->version_number]);MessageUtil::setMessage('Invoice PDF emailed to '.$to.'.','Invoices','success');
        } else {
            $overrides=[];foreach((array)($_POST['line_description']??[]) as $lineId=>$description)$overrides[(string)(int)$lineId]=trim((string)$description);
            $service->updateEditable($owner,$id,$actor,['billing_name'=>trim((string)($_POST['billing_name']??'')),'billing_email'=>trim((string)($_POST['billing_email']??'')),'billing_phone'=>trim((string)($_POST['billing_phone']??'')),'billing_address'=>trim((string)($_POST['billing_address']??'')),'invoice_notes'=>trim((string)($_POST['invoice_notes']??'')),'internal_reference'=>trim((string)($_POST['internal_reference']??'')),'line_overrides_json'=>json_encode($overrides,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);MessageUtil::setMessage('Invoice-specific details saved. The original order was not changed.','Invoices','success');
        }
    }catch(Throwable $e){MessageUtil::setMessage($e->getMessage(),'Invoice action failed','error');}
    LocationUtils::reload();
});
$router->run();
