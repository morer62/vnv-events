<?php

use App\Utils\TemplateResponse;



$delimiter = "<body>";
$includeView = $_SESSION["includeView"];


$data = TemplateResponse::renderInTemplates('base.twig');
[$bodyStart, $bodyEnd] = explode($delimiter, $data, 2);


echo "$bodyStart $delimiter";
include $includeView;
echo $bodyEnd;


$_SESSION["includeView"] = null;