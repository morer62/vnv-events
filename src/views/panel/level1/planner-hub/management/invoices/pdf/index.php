<?php

use App\Services\InvoicePdfGenerator;
use App\Services\InvoiceService;
use App\Services\LoginService;

$session=LoginService::getSession();$id=(int)($_GET['id']??0);$download=($_GET['download']??'')==='1';
try{
    $service=new InvoiceService();
    if($download){
        $issued=$service->issueVersion((int)$session->getOwner(),$id,(int)$session->getId());
        $snapshot=$issued['snapshot'];$version=(int)$issued['version']->version_number;
    }else{
        $snapshot=$service->snapshot((int)$session->getOwner(),$id);$version='PREVIEW';
    }
    $invoice=$snapshot['invoice'];$pdf=InvoicePdfGenerator::render($snapshot,$version);
    $filename=$invoice->invoice_number.($download?'-v'.$version:'-preview').'.pdf';
    header('Content-Type: application/pdf');
    header('Content-Disposition: '.($download?'attachment':'inline').'; filename="'.$filename.'"');
    header('Content-Length: '.strlen($pdf));echo $pdf;
}
catch(Throwable $e){http_response_code(404);header('Content-Type: text/plain; charset=utf-8');echo $e->getMessage();}
