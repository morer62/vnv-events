<?php

// No uses Router, ni clases extra, porque puede estar causando salida no controlada

// Numero de Whatsapp primario      9548073945
//                                  +19548073945

ini_set('display_errors', 1);
error_reporting(E_ALL);

// $forwardTo1 = '+19546963089'; // Numero de Mary 2
$forwardTo2 = '+13053761210'; // Numero de Mary 1
$forwardTo3 = '+13052045073'; // Este es el numero de Kasie-Dorian  y el numero de Kim


header('Content-Type: application/xml');
echo <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<Response>
  <Dial>
    <Number>{$forwardTo1}</Number>
    <Number>{$forwardTo2}</Number>
    <Number>{$forwardTo3}</Number>
  </Dial>
</Response>
XML;
exit;
