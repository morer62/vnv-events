<?php

use App\Repositories\OrdersServiceRepository;
use App\Repositories\Connection;
use App\Services\MochiActionService;
use App\Utils\Cors;
use App\Utils\JsonResponse;
use App\Utils\Router;

Cors::handle();
$rawBody = (string)file_get_contents('php://input');

$authenticate = static function () use ($rawBody): ?JsonResponse {
    $secretFile = $_ENV['MOCHI_VNV_SECRET_FILE'] ?? '/home/ophyra/.mochi-vnv-secret';
    $timestamp = (string)($_SERVER['HTTP_X_MOCHI_TIMESTAMP'] ?? '');
    $provided = strtolower((string)($_SERVER['HTTP_X_MOCHI_SIGNATURE'] ?? ''));
    if (!is_readable($secretFile) || !ctype_digit($timestamp) || abs(time() - (int)$timestamp) > 300 || !preg_match('/^[a-f0-9]{64}$/', $provided)) {
        return new JsonResponse(['error' => 'integration_authentication_required'], 401);
    }
    $secret = trim((string)file_get_contents($secretFile));
    $expected = hash_hmac('sha256', $timestamp."\n".$rawBody, $secret);
    if (!hash_equals($expected, $provided)) return new JsonResponse(['error' => 'invalid_integration_signature'], 401);
    return null;
};

$router = new Router();
$router->get(static function () use ($authenticate) {
    if ($error = $authenticate()) return $error;
    $ownerId = max(1, (int)($_ENV['VNV_EVENTS_OWNER_ID'] ?? 2));
    $action = (string)($_GET['action'] ?? 'health');
    if ($action === 'health') return new JsonResponse(['status' => 'ok', 'service' => 'vnv-events-commercial-api']);
    if ($action !== 'catalog') return new JsonResponse(['error' => 'unsupported_action'], 404);
    $services = (new OrdersServiceRepository())->getAllByInstitutionOwner($ownerId);
    $items = array_map(static fn(object $service): array => [
        'id' => (int)$service->id,
        'name' => (string)$service->name,
        'description' => (string)($service->description ?? ''),
        'price' => round((float)($service->price ?? 0), 2),
        'variable_price' => strtoupper((string)($service->is_variable ?? 'NO')) === 'YES',
    ], $services);
    return new JsonResponse(['items' => $items, 'count' => count($items), 'source' => 'VNV Events']);
});
$router->post(static function () use ($authenticate, $rawBody) {
    if ($error = $authenticate()) return $error;
    $input=json_decode($rawBody,true);if(!is_array($input))return new JsonResponse(['error'=>'invalid_json'],400);
    if(($input['action']??'')!=='create_estimate_draft')return new JsonResponse(['error'=>'unsupported_action'],404);
    $key=trim((string)($input['idempotency_key']??''));$approvedBy=strtolower(trim((string)($input['approved_by_email']??'')));$payload=(array)($input['estimate']??[]);
    if(!preg_match('/^[A-Za-z0-9._:-]{8,120}$/',$key)||!filter_var($approvedBy,FILTER_VALIDATE_EMAIL))return new JsonResponse(['error'=>'idempotency_key_and_approver_required'],422);
    $ownerId=max(1,(int)($_ENV['VNV_EVENTS_OWNER_ID']??2));$hash=hash('sha256',$rawBody);$db=new Connection();
    $db->query('SELECT request_hash,status,result_json FROM mochi_integration_requests WHERE idempotency_key=:key LIMIT 1');$db->bind(':key',$key);$existing=$db->fetchOne();
    if($existing){
        if(!hash_equals((string)$existing->request_hash,$hash))return new JsonResponse(['error'=>'idempotency_conflict'],409);
        if($existing->status==='COMPLETED'){$result=json_decode((string)($existing->result_json??'{}'),true)?:[];return new JsonResponse($result+['idempotent'=>true,'status'=>'COMPLETED'],200);}
        if($existing->status==='PROCESSING')return new JsonResponse(['error'=>'request_already_processing'],409);
    }
    // The signed MOCHI reviewer remains part of the audit trail, while writes run
    // under a real active VNV Level 1 service actor belonging to this tenant.
    $actorEmail=strtolower(trim((string)($_ENV['MOCHI_VNV_ACTOR_EMAIL']??'')));
    if($actorEmail!==''){$db->query("SELECT id,level,email FROM users WHERE LOWER(email)=LOWER(:email) AND id_owner=:owner AND level=1 AND is_active=1 LIMIT 1");$db->bind(':email',$actorEmail);$db->bind(':owner',$ownerId);$actor=$db->fetchOne();}
    else{$db->query("SELECT id,level,email FROM users WHERE id_owner=:owner AND level=1 AND is_active=1 ORDER BY id ASC LIMIT 1");$db->bind(':owner',$ownerId);$actor=$db->fetchOne();}
    if(!$actor)return new JsonResponse(['error'=>'authorized_level_1_service_actor_not_found'],503);
    try{
        if($existing){$db->query("UPDATE mochi_integration_requests SET status='PROCESSING',result_json=NULL,completed_at=NULL WHERE idempotency_key=:key AND status='FAILED'");$db->bind(':key',$key);$db->execute();if($db->rowCount()!==1)return new JsonResponse(['error'=>'idempotency_retry_failed'],409);}
        else{$db->query("INSERT INTO mochi_integration_requests(idempotency_key,request_hash,status) VALUES(:key,:hash,'PROCESSING')");$db->bind(':key',$key);$db->bind(':hash',$hash);$db->execute();}
    }catch(\Throwable){return new JsonResponse(['error'=>'idempotency_reservation_failed'],409);}
    try{$result=(new MochiActionService($ownerId,'vnvevents'))->createApprovedIntegrationEstimate($payload,(int)$actor->id,(int)$actor->level);$result['approved_by_mochi']=$approvedBy;$result['executed_by_vnv']=(string)$actor->email;$stored=json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);$db->query("UPDATE mochi_integration_requests SET status='COMPLETED',result_json=:result,completed_at=UTC_TIMESTAMP() WHERE idempotency_key=:key");$db->bind(':result',$stored);$db->bind(':key',$key);$db->execute();return new JsonResponse($result,201);}
    catch(\InvalidArgumentException $e){$error=['error'=>'invalid_estimate_payload','message'=>$e->getMessage()];}
    catch(\Throwable $e){error_log('[MOCHI signed estimate] '.$e->getMessage());$error=['error'=>'estimate_creation_failed'];}
    $db->query("UPDATE mochi_integration_requests SET status='FAILED',result_json=:result,completed_at=UTC_TIMESTAMP() WHERE idempotency_key=:key");$db->bind(':result',json_encode($error));$db->bind(':key',$key);$db->execute();return new JsonResponse($error,422);
});
$router->run();
