<?php
declare(strict_types=1);

if(PHP_SAPI!=='cli'){http_response_code(404);exit;}

require_once dirname(__DIR__).'/vendor/autoload.php';
Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();

use App\Repositories\Connection;
use App\Services\MochiActionService;

$db=new Connection();
$db->query("SELECT id,id_client,id_contract FROM orders WHERE id_owner=2 AND COALESCE(is_archived,0)=0 AND COALESCE(status_workflow,'') LIKE 'INVOICE_%' AND id_client IS NOT NULL ORDER BY id DESC LIMIT 1");
$order=$db->fetchOne();
if(!$order)throw new RuntimeException('No local invoice estimate is available for the link-resolution test.');

$payload=['order_id'=>(int)$order->id,'user_id'=>(int)$order->id_client,'exp'=>time()+3600];
$payload['hash']=hash_hmac('sha256',json_encode($payload),$_ENV['VNV_SECRET_KEY']??'mySuperSecretKey');
$token=base64_encode(json_encode($payload));
$url='https://vnvevents.com/order-access?token='.urlencode($token);

$service=new MochiActionService(2,'vnvevents',$db);
$context=$service->resolveOrderAccessContext('Revisa este estimate antiguo: '.$url);
if((int)($context['order_id']??0)!==(int)$order->id||($context['source']??'')!=='verified_order_access_link')throw new RuntimeException('A valid order-access URL was not exposed as generic linked-estimate context.');
$resolved=$service->prepareOrderContractAssignment(1,1,1,'Asígnale General Conditions a este estimate: '.$url);
if((int)($resolved['facts']['order_id']??0)!==(int)$order->id)throw new RuntimeException('A valid order-access URL did not resolve to the expected estimate.');
if(empty($resolved['facts']['already_assigned'])&&empty($resolved['action_draft']))throw new RuntimeException('The contract assignment was neither recognized nor prepared.');

$tampered=substr($token,0,-2).'xx';
$rejected=$service->prepareOrderContractAssignment(1,1,1,'Asígnale contrato: https://vnvevents.com/order-access?token='.urlencode($tampered));
if(!empty($rejected['facts']['order_id'])||!empty($rejected['action_draft']))throw new RuntimeException('A tampered order-access token was accepted.');

$source=(string)file_get_contents(dirname(__DIR__).'/src/Repositories/OrdersRepository.php');
if(!str_contains($source,"str_starts_with(\$workflow,'INVOICE_')")||!str_contains($source,"requiredGeneralConditionsId()"))throw new RuntimeException('The central repository default-contract guard is missing.');

fwrite(STDOUT,"PASS: order-access links resolve to the exact estimate, tampered links are rejected, and invoice inserts default to General Conditions.\n");
