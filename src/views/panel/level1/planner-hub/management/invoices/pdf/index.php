<?php

use App\Services\InvoicePdfGenerator;
use App\Services\InvoiceService;
use App\Services\LoginService;

$session=LoginService::getSession();$id=(int)($_GET['id']??0);
try{$issued=(new InvoiceService())->issueVersion((int)$session->getOwner(),$id,(int)$session->getId());$invoice=$issued['snapshot']['invoice'];$pdf=InvoicePdfGenerator::render($issued['snapshot'],(int)$issued['version']->version_number);header('Content-Type: application/pdf');header('Content-Disposition: inline; filename="'.$invoice->invoice_number.'-v'.$issued['version']->version_number.'.pdf"');header('Content-Length: '.strlen($pdf));echo $pdf;}
catch(Throwable $e){http_response_code(404);header('Content-Type: text/plain; charset=utf-8');echo $e->getMessage();}
